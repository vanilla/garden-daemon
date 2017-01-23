<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Garden\Daemon;

use Garden\Cli\Cli;
use Garden\Container\Container;

use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;

use Interop\Container\ContainerInterface;

/**
 * Daemon manager
 *
 * Abstracts forking-to-daemon logic away from application logic. Also handles
 * child pool management.
 *
 * @todo When forking, fork into two processes and use one as a clean control to
 * watch the other. When the worker ends, inspect the exit state and determine
 * whether to reload or fully quit and restart from cron.
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package garden-daemon
 */
class Daemon implements ContainerInterface, LoggerAwareInterface {

    use LoggerAwareTrait;

    const MODE_SINGLE = 'single';
    const MODE_FLEET = 'fleet';

    const APP_EXIT_HALT         = 'halt';       // ok
    const APP_EXIT_EXIT         = 'exit';       // error
    const APP_EXIT_RESTART      = 'restart';    // restart
    const APP_EXIT_RELOAD       = 'reload';     // reload

    /*
     * Daemon identification
     */

    protected $parentPid;
    protected $daemonPid;
    protected $childPid;
    protected $isChild;
    protected $pidFile;
    protected $realm;

    /**
     * Coordination instance
     * @var app
     */
    protected $instance = null;

    /**
     * Active child processes
     * @var array
     */
    protected $children;

    /**
     * Monitor child runtimes
     * @var array
     */
    protected $runtimes;

    /**
     * Daemon options
     * @var array
     */
    protected $options;

    /**
     * CLI
     * @var \Garden\Cli\Cli
     */
    protected $cli;

    /**
     * CLI Args
     * @var \Garden\Cli\Args
     */
    protected $args;

    /**
     * DI Container
     * @var \Garden\Container\Container
     */
    protected $di;

    /**
     * Lock manager
     * @var \Garden\Daemon\Lock
     */
    protected $lock;

    /**
     * Configuration store
     * @var array
     */
    protected $config;

    public $logLevel = -1;
    protected $exitMode = 'success';
    protected $exit = 0;

    /*
     * Logging output modifiers
     */
    const LOG_O_SHOWTIME = 1;

    public function __construct(Cli $cli, Container $di, array $options, array $config) {
        $this->parentPid = posix_getpid();
        $this->daemonPid = null;
        $this->childPid = null;
        $this->children = [];
        $this->realm = 'console';

        $this->cli = $cli;
        $this->di = $di;
        $this->options = array_merge($options, $config);
    }

    /**
     * Pre Configure Daemon
     *
     * @param array $options
     */
    public function configure(array $options = []) {
        $this->options = array_merge($this->options, $options);
    }

    /**
     * Retrieve configuration option
     *
     * @param string $option
     * @param mixed $default
     * @return mixed
     */
    public function get($option, $default = null) {
        return $this->options[$option] ?? $default;
    }

    /**
     * Set/Modify configuration option
     *
     * @param string $option
     * @param mixed $value
     * @return mixed
     */
    public function set($option, $value) {
        return $this->options[$option] = $value;
    }

    /**
     * Check if configuration option is set
     *
     * @param string $option
     * @return boolean
     */
    public function has($option) {
        return array_key_exists($option, $this->options);
    }

    /**
     * Get CLI args
     *
     * @return \Garden\Cli\Args
     */
    public function getArgs() {
        return $this->get('args');
    }

    /**
     * Attach to process
     *
     * @param array $arguments
     * @return void
     * @throws DaemonException
     */
    public function attach(array $arguments = null): int {

        if (!$this->has('appname')) {
            throw new Exception("Must set appname in order to run daemon.", 500);
        }

        if (!$this->has('appdir')) {
            throw new Exception("Must set appdir in order to run daemon.", 500);
        }

        $appName = $this->get('appname');

        $appID = strtolower($appName);
        $runFile = paths('/var/run', "{$appID}.pid");

        $this->lock = new Lock($runFile);

        // Basic configuration

        $appName = $this->get('appname');
        $appDescription = $this->get('appdescription');

        // Logging

        $this->logLevel = $this->get('loglevel', LogLevel::WARNING);

        // Set up app

        // Prepare global CLI

        $this->log(LogLevel::INFO, "{app} v{version}", [
            'app'       => APP,
            'version'   => APP_VERSION
        ]);

        $this->cli
            ->description($appDescription)
            ->meta('filename', $appName)

            ->command('start')
                ->description('Start the application.')
                ->opt('watchdog:w', "Don't announce failures to start", false, 'boolean')

            ->command('stop')
                ->description('Stop the application.')

            ->command('restart')
                ->description('Stop the application, then start it again.')

            ->command('install')
                ->description('Install command symlink to /usr/bin');

        $application = $this->getInstance();
        $this->di->call([$application, 'preflight']);

        // Parse CLI
        $this->args = $this->cli->parse($arguments, true);
        $this->di->setInstance(Args::class, $this->args);
        $this->set('args', $this->args);

        $command = $this->args->getCommand();
        $sysDaemonize = $this->get('daemonize', true);
        if (!$sysDaemonize) {
            $command = 'start';
        }

        $exitCode = null;
        switch ($command) {

            // Install symlink
            case 'install':
                break;

            // Stop or restart daemon
            case 'stop':
            case 'restart':

                // Check if pid file is currently running
                $runPid = $this->lock->getRunningPID();
                $isRunning = $this->lock->isLocked();

                if ($isRunning) {

                    // Stop it
                    posix_kill($runPid, SIGTERM);
                    sleep(1);

                    // Check if it's still running
                    if ($this->lock->isProcessRunning($runPid)) {

                        // Kill it harder
                        posix_kill($runPid, SIGKILL);
                        sleep(1);

                        // Check if it's still running
                        if ($this->lock->isProcessRunning($runPid)) {
                            $this->log(LogLevel::WARNING, 'unable to stop daemon');
                            return 1;
                        }
                    }
                }

                if ($command == 'stop') {
                    $message = 'stopped';
                    if (!$isRunning) {
                        $message = 'not running';
                    }
                    throw new Exception(" - {$message}", 500);
                } else {
                    $this->log(LogLevel::WARNING, 'restarting...');
                }

            // Start daemon
            case 'start':

                // Check for currently running instance
                $runConcurrent = $this->get('concurrent', false);
                if (!$runConcurrent) {

                    // Check locks
                    $runPid = $this->lock->getRunningPID();
                    $isRunning = $this->lock->isLocked();

                    if ($isRunning) {
                        $watchdog = $this->args->getOpt('watchdog');
                        $code = $watchdog ? 0 : 1;

                        $this->log(LogLevel::INFO, 'already running');
                        return $code;
                    }
                }

                // Running user
                $uid = posix_geteuid();
                $user = posix_getpwuid($uid);
                $this->set('user', $user);

                $this->log(LogLevel::DEBUG, "running as {user} (uid {uid})", [
                    'uid'   => $uid,
                    'user'  => $user['name']
                ]);

                // Make sure we can do our things
                $sysUser = $this->get('runasuser', null);
                $sysGroup = $this->get('runasgroup', null);
                if ($sysUser || $sysGroup) {
                    if ($user['uid'] != 0) {
                        $this->log(LogLevel::ERROR, 'must be running as root to setegid() or seteuid()');
                        return 1;
                    }
                }

                // Daemonize
                if ($sysDaemonize) {

                    $realm = $this->fork('daemon', true);
                    $this->realm = $realm;

                    // Console returns 0
                    if ($realm == 'console') {
                        $this->log(LogLevel::DEBUG, "[{pid}]   console detached, parent exiting");
                        return 0;
                    }

                } else {

                    $this->log(LogLevel::DEBUG, "[{pid}] Will not go into background");
                    $this->realm = 'daemon';

                }

                // We are now a daemon (or just faking it)
                $this->initialize();

                // Daemon returns execution to the main file
                $this->daemonPid = posix_getpid();

                // Invoking user
                $iusername = trim(shell_exec('logname'));
                $iuser = posix_getpwnam($iusername);
                $this->set('iuser', $iuser);

                // Current terminal name
                $terminal = posix_ttyname(STDOUT);
                $terminal = str_replace('/dev/', '', $terminal);
                $this->set('itty', $terminal);

                // Coordinate fleet
                $sysCoordinate = $this->get('coordinate', false);
                if ($sysCoordinate) {
                    $this->coordinate();
                }

                $sysMode = $this->get('mode', self::MODE_SINGLE);
                switch ($sysMode) {
                    case self::MODE_SINGLE:

                        // Run app
                        $this->runModeSingle();

                        break;

                    case self::MODE_FLEET:

                        // Launch and maintain fleet
                        $this->runModeFleet();

                        break;
                }

                // Dismiss payload
                if ($sysCoordinate) {
                    $this->dismissCoordinator();
                }

                // Pipe exit code to wrapper file
                $exitCode = $this->exit;

                break;

            default:
                $exitHandled = null;

                // Hand off control to app
                if (method_exists($application, 'cli')) {
                    $exitHandled = call_user_func([$application, 'cli'], $this->args);
                }

                // Command not handled by app
                if (is_null($exitHandled)) {
                    throw new Exception("Unhandled command", 400);
                }
                break;
        }

        return $exitCode;
    }

    /**
     * Post-daemonize initialization
     *
     */
    protected function initialize() {
        declare (ticks = 100);

        // Install signal handlers
        pcntl_signal(SIGHUP, array($this, 'handleSignal'));
        pcntl_signal(SIGINT, array($this, 'handleSignal'));
        pcntl_signal(SIGTERM, array($this, 'handleSignal'));
        pcntl_signal(SIGCHLD, array($this, 'handleSignal'));

        // Inform app that we've formed
        //$this->getInstance()->initialize($this->args);
        $this->di->call([$this->getInstance(), 'initialize']);
    }

    /**
     * Get an instance of the app
     *
     * @return AppInterface
     */
    protected function getInstance(): AppInterface {
        if (!($this->instance instanceof AppInterface)) {
            $appName = $this->get('appname', null);
            $appNamespace = $this->get('appnamespace', null);

            // Run App
            $appClassName = ucfirst($appName);
            if (!is_null($appNamespace)) {
                $appClassName = "\\{$appNamespace}\\{$appClassName}";
            }

            $this->instance = $this->di->get($appClassName);
        }
        return $this->instance;
    }

    /**
     * Fork into the background
     *
     * @param string $mode return realm label provider
     * @param bool $lock optional. false gives no lock protection, true re-locks on $this->lock
     */
    protected function fork(string $mode, bool $lock = false) {

        $modes = [
            'daemon' => [
                'parent' => 'console',
                'child' => 'daemon'
            ],
            'fleet' => [
                'parent' => 'daemon',
                'child' => 'worker'
            ]
        ];
        if (!array_key_exists($mode, $modes)) {
            return false;
        }

        // Fork
        $pid = pcntl_fork();

        // Realm splitting
        if ($pid > 0) {

            $realm = val('parent', $modes[$mode]);

            // Parent
            $this->log(LogLevel::DEBUG, "[{pid}] Parent ({$realm})");

            // Record child PID
            $childRealm = val('child', $modes[$mode]);
            $this->children[$pid] = $childRealm;

            // Return as parent
            return $realm;

        } else if ($pid == 0) {

            $this->realm = val('child', $modes[$mode]);

            // Child
            $this->log(LogLevel::DEBUG, "[{pid}] Child ({$this->realm})");

            // Re-lock process
            if ($lock) {
                $this->log(LogLevel::DEBUG, "[{pid}]   locking child process");
                $locked = $this->lock->lock();
                if (!$locked) {
                    $this->log(Daemon::LOG_L_WARN, "[{pid}] Unable to lock forked process");
                    exit;
                }
            }

            // Detach
            $this->log(LogLevel::DEBUG, "[{pid}]   detach from {parent}", [
                'parent' => $modes[$mode]['parent']
            ]);
            if (posix_setsid() == -1) {
                $this->log(LogLevel::DEBUG, "[{pid}] Unable to detach from {parent}", [
                    'parent' => $modes[$mode]['parent']
                ]);
                exit;
            }

            // SETGID/SETUID
            $sysGroup = $this->get('runasgroup', null);
            if (!is_null($sysGroup)) {

                $sysGroupInfo = posix_getgrnam($sysGroup);
                if (is_array($sysGroupInfo)) {
                    $sysGID = val('gid', $sysGroupInfo, null);
                    if (!is_null($sysGID)) {
                        $sysSetegid = posix_setegid($sysGID);
                        $sysSetegid = $sysSetegid ? 'success' : 'failed';
                    }
                    $this->log(LogLevel::DEBUG, "[{pid}]   setegid... {$sysSetegid}");
                } else {
                    $this->log(LogLevel::WARNING, "[{pid}]   setegid, no such group '{$sysGroup}'");
                }
            }

            $sysUser = $this->get('runasuser', null);
            if (!is_null($sysUser)) {
                $sysUserInfo = posix_getpwnam($sysUser);
                if (is_array($sysUserInfo)) {
                    $sysUID = val('uid', $sysUserInfo, null);
                    if (!is_null($sysUID)) {
                        $sysSeteuid = posix_seteuid($sysUID);
                        $sysSeteuid = $sysSeteuid ? 'success' : 'failed';
                    }
                    $this->log(LogLevel::DEBUG, "[{pid}]   seteuid... {$sysSeteuid}");
                } else {
                    $this->log(LogLevel::WARN, "[{pid}]   seteuid, no such user '{$sysUser}'");
                }
            }

            // Close resources
            /*
            $this->log(LogLevel::DEBUG, "[{pid}]   close console I/O");
            fclose(STDIN);
            fclose(STDOUT);
            fclose(STDERR);
            */

            // Return as child
            return $this->realm;

        } else {

            // Failed
            $this->log(LogLevel::ERROR, "[{pid}] Failed to fork process");
            exit(1);

        }
    }

    /**
     * Instantiate and coordinate
     *
     * If 'coordinate' is true, we need to create an instance of the payload
     * class and execute its 'coordinate' method. This is a situation where the
     * payload class is acting as a fleet dispatcher for the sysFleet.
     *
     * Instead of instantiating payloads after forking, we form with the payload
     * already prepared.
     *
     * @internal POST FORK, PRE FLEET
     */
    protected function coordinate() {
        $this->getInstance();

        if (method_exists($this->instance, 'coordinate')) {
            $this->instance->coordinate();
        }
    }

    /**
     * Run mode: single
     *
     * Run application instance as a daemon, returning when the app finishes.
     *
     */
    protected function runModeSingle() {
        $this->log(LogLevel::INFO, "[{pid}] Running application: single");

        // Sleep for 2 seconds
        sleep(2);

        $this->runPayloadApplication();
    }

    /**
     * Run mode: fleet
     *
     * Loiter, launching fleet workers and waiting for them to land. Cycle until
     * launching is disabled.
     *
     */
    protected function runModeFleet() {
        $this->log(LogLevel::INFO, "[{pid}] Running application: fleet");

        // Sleep for 2 seconds
        sleep(2);

        $this->exitMode = $this->get('exitmode', 'success');

        $maxFleetSize = $this->get('fleet', 1);
        $this->log(LogLevel::INFO, "[{pid}] Launching worker fleet with {$maxFleetSize} workers");
        do {

            // Launch workers until the fleet is deployed
            if ($this->get('launching', true) && $maxFleetSize) {
                do {
                    $fleetSize = $this->fleetSize();
                    if ($fleetSize >= $maxFleetSize) {
                        break;
                    }
                    $launched = $this->launchFleetWorker();

                    // If a child gets through, terminate as a failure
                    if ($this->realm != 'daemon') {
                        exit(1);
                    }

                    // Turn off launching if we didn't launch
                    if (!$launched) {
                        $this->set('launching', false);
                    }

                    /*
                     *
                     * DAEMON THREAD BELOW
                     *
                     */

                    if ($launched) {
                        $fleetSize++;
                    } else {
                        if ($launched === false) {
                            $this->log(LogLevel::WARN, "[{pid}] Failed to launch worker, moving on to cleanup");
                        }
                    }
                } while ($launched && $fleetSize < $maxFleetSize);
            }

            pcntl_signal_dispatch();

            // Clean up any exited children
            do {
                $status = null;
                $pid = pcntl_wait($status, WNOHANG);
                if ($pid > 0) {
                    $this->land($pid, $status);
                }
            } while ($pid > 0);

            $fleetSize = $this->fleetSize();
            $launching = $this->get('launching', true) ? 'on' : 'off';
            $this->log(LogLevel::DEBUG, "[{pid}] Reaping fleet, currently {$fleetSize} outstanding, launching is {$launching}");

            // Wait a little (dont tightloop)
            sleep(1);

        } while ($this->get('launching', true) || $fleetSize);

        // Shut down signal handling in main process
        pcntl_signal(SIGHUP, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGCHLD, SIG_DFL);
    }

    /**
     * Launch a fleet worker
     *
     * @return bool
     */
    protected function launchFleetWorker(): bool {
        $this->log(LogLevel::DEBUG, "[{pid}]   launching fleet worker");

        // Prepare current state priot to forking
        if (!is_null($this->instance) && method_exists($this->instance, 'prepareProcess')) {
            $canLaunch = $this->instance->prepareProcess();
            if (!$canLaunch) {
                return $canLaunch;
            }
        }

        $this->fork('fleet');
        if ($this->realm != 'worker') {
            return true;
        }

        /*
         *
         * WORKER THREADS BELOW
         *
         */

        // Workers don't care about signal handling
        pcntl_signal(SIGHUP, SIG_DFL);
        pcntl_signal(SIGINT, SIG_DFL);
        pcntl_signal(SIGTERM, SIG_DFL);
        pcntl_signal(SIGCHLD, SIG_DFL);

        $exitCode = $this->runPayloadApplication();
        exit($exitCode);
    }

    /**
     * Dismiss coordinator
     *
     * @internal POST LOITER
     */
    protected function dismissCoordinator() {
        $this->getInstance();

        if (!is_null($this->instance) && method_exists($this->instance, 'dismiss')) {
            $this->instance->dismissCoordinator();
        }
    }

    /**
     * Run application worker
     *
     * @internal POST FORK, POST FLEET
     * @return int
     */
    protected function runPayloadApplication(): int {
        $this->getInstance();

        try {
            $runSuccess = $this->instance->run();
            unset($this->instance);
        } catch (Exception $ex) {
            $exitMessage = $ex->getMessage();
            $exitFile = $ex->getFile().':'.$ex->getLine();
            $this->log(LogLevel::ERROR, "[{pid}] App Exception: {$exitMessage} {$exitFile}");
            return 1;
        }

        $this->log(LogLevel::DEBUG, "[{pid}] App exited with status: {$runSuccess}");

        // If this was not a controlled exit
        $exitCode = 0;
        switch ($runSuccess) {
            case self::APP_EXIT_EXIT:
                $this->log(LogLevel::DEBUG, "[{pid}] Halting from error condition...");
                $exitCode = 8;
                break;

            case self::APP_EXIT_HALT:
                $this->log(LogLevel::DEBUG, "[{pid}] Halting from normal operation...");
                $exitCode = 0;
                break;

            case self::APP_EXIT_RESTART:
                $this->log(LogLevel::DEBUG, "[{pid}] Gracefully exiting (cron restart)...");
                $exitCode = 2;
                break;

            case self::APP_EXIT_RELOAD:
            default:
                $this->log(LogLevel::DEBUG, "[{pid}] Preparing to reload...");
                $exitCode = 1;
                break;
        }
        return $exitCode;
    }

    /**
     * Catch signals
     *
     * @param int $signal
     */
    public function handleSignal(int $signal) {
        $this->log(LogLevel::DEBUG, "[{pid}] Caught signal '{$signal}'");

        switch ($signal) {

            // Daemon was asked to restart
            case SIGHUP:
                if ($this->realm == 'daemon') {
                    throw new Exception("Restart", 100);
                }
                break;

            // Daemon is exiting, kill children
            case SIGINT:
            case SIGTERM:
                if ($this->realm == 'daemon') {
                    if ($this->instance) {
                        if (is_callable([$this->instance, 'shutdown'])) {
                            $this->instance->shutdown();
                        }
                    }
                    $this->genocide();
                    throw new Exception("Shutdown", 200);
                }
                break;

            // Child process exited
            case SIGCHLD:
                if ($this->realm == 'daemon') {
                    do {
                        $status = null;
                        $pid = pcntl_wait($status, WNOHANG);
                        if ($pid > 0) {
                            $this->land($pid, $status);
                        }
                    } while ($pid > 0);

                    //$pid = pcntl_waitpid(-1, $status, WNOHANG);
                    //if ($pid > 0)
                    //   $this->land($pid, $status);
                }
                break;

            // Custom signal - Nothing
            case SIGUSR1:
                break;

            // Custom signal - Nothing
            case SIGUSR2:
                break;
        }
    }

    /**
     * How big is our fleet of workers right now?
     *
     * @return int
     */
    public function fleetSize(): int {
        return sizeof($this->children);
    }

    /**
     * Recover a fleet worker
     *
     * @param int $pid
     * @param int $status
     */
    protected function land(int $pid, int $status = null) {
        // One of ours?
        if (array_key_exists($pid, $this->children)) {

            $exited = pcntl_wexitstatus($status);
            if ($this->exitMode == 'worst-case') {
                if (abs($exited) > abs($this->exit)) {
                    $this->exit = $exited;
                }
            }

            $workerType = val($pid, $this->children);
            unset($this->children[$pid]);
            $fleetSize = $this->fleetSize();
            $this->log(LogLevel::DEBUG, "[{pid}] Landing fleet '{$workerType}' with PID {$pid} ({$fleetSize} still in the air)");
        }
    }

    /**
     * Kill all children and return
     *
     * @return bool
     */
    protected function genocide(): bool {
        static $killing = false;
        if (!$killing) {
            $this->log(LogLevel::DEBUG, "[{pid}] Shutting down fleet operations...");
            $killing = true;
            foreach ($this->children as $childpid => $childtype) {
                posix_kill($childpid, SIGKILL);
            }

            // Re-send missed signals
            pcntl_signal_dispatch();

            // Wait for children to exit
            while ($this->fleetSize()) {
                do {
                    $status = null;
                    $pid = pcntl_wait($status, WNOHANG);
                    if ($pid > 0) {
                        $this->land($pid, $status);
                    }
                } while ($pid > 0);
                usleep(10000);
            }
            return true;
        }
        return false;
    }

    /**
     * Get the time
     *
     * @param string $time
     * @param string $format
     * @return \DateTimeInterface
     */
    public static function time(string $time = 'now', string $format = null): \DateTimeInterface {
        $timezone = new \DateTimeZone('utc');

        if (is_null($format)) {
            $date = new \DateTime($time, $timezone);
        } else {
            $date = \DateTime::createFromFormat($format, $time, $timezone);
        }

        return $date;
    }

    /**
     * Get a logger
     *
     * @return \Psr\Log\LoggerInterface
     */
    public function getLogger() {
        if (!($this->logger instanceof LoggerInterface)) {
            return new NullLogger;
        }
        return $this->logger;
    }

    /**
     * Output to log (screen or file or both)
     *
     * @param string $level logger event level
     * @param string $message
     * @param array $context optional.
     * @param type $options optional.
     */
    public function log(string $level, string $message, array $context = [], int $options = 1) {
        $format = '';
        $priority = $this->levelPriority($level);

        if (!is_array($context)) {
            $context = [];
        }
        $context = array_merge([
            'priority' => $priority,
            'pid' => posix_getpid(),
            'time' => Daemon::time('now')->format('Y-m-d H:i:s')
        ], $context);

        $loggingPriority = $this->levelPriority($this->logLevel);

        if ($this->logLevel == -1 || ($loggingPriority && $loggingPriority >= $priority)) {
            if ($options & Daemon::LOG_O_SHOWTIME) {
                $format .= "[{time}]";
            }

            // Pad output if there are tags
            if (strlen($format)) {
                $format .= " ";
            }

            $format .= $message;

            $this->getLogger()->log($level, $format, $context);
        }
    }

    /**
     * Get the numeric priority for a log level.
     *
     * The priorities are set to the LOG_* constants from the {@link syslog()} function.
     * A lower number is more severe.
     *
     * @param string|int $level The string log level or an actual priority.
     * @return int Returns the numeric log level or `8` if the level is invalid.
     */
    public function levelPriority(string $level): int {
        static $priorities = [
            LogLevel::DEBUG     => LOG_DEBUG,
            LogLevel::INFO      => LOG_INFO,
            LogLevel::NOTICE    => LOG_NOTICE,
            LogLevel::WARNING   => LOG_WARNING,
            LogLevel::ERROR     => LOG_ERR,
            LogLevel::CRITICAL  => LOG_CRIT,
            LogLevel::ALERT     => LOG_ALERT,
            LogLevel::EMERGENCY => LOG_EMERG
        ];

        if (isset($priorities[$level])) {
            return $priorities[$level];
        } else {
            return LOG_DEBUG + 1;
        }
    }

    /**
     * Interpolate contexts into messages containing bracket-wrapped format strings.
     *
     * @param string $format
     * @param array $context optional. array of key-value pairs to replace into the format.
     * @return string
     */
    protected function interpolateContext(string $format, array $context = []): string {
        $final = preg_replace_callback('/{([^\s][^}]+[^\s]?)}/', function ($matches) use ($context) {
            $field = trim($matches[1], '{}');
            if (array_key_exists($field, $context)) {
                return $context[$field];
            } else {
                return $matches[1];
            }
        }, $format);
        return $final;
    }

}
