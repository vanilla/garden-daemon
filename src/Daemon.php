<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Garden\Daemon;

use Garden\Cli\Cli;
use Garden\Container\Container;

use Psr\Log\LogLevel;
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
    use LoggerBoilerTrait;

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
     * Logging levels
     */

    const LOG_L_FATAL = 1;
    const LOG_L_WARN = 2;
    const LOG_L_NOTICE = 4;
    const LOG_L_INFO = 8;
    const LOG_L_THREAD = 16;
    const LOG_L_APP = 32;
    const LOG_L_EVENT = 64;
    const LOG_L_API = 128;
    const LOG_L_ALL = 255;

    /*
     * Logging output modifiers
     */
    const LOG_O_NONEWLINE = 1;
    const LOG_O_SHOWTIME = 2;
    const LOG_O_SHOWPID = 4;
    const LOG_O_ECHO = 8;

    public function __construct(Cli $cli, Container $di, array $options, array $config) {
        $this->parentPid = posix_getpid();
        $this->daemonPid = null;
        $this->childPid = null;
        $this->children = [];
        $this->realm = 'console';

        $this->cli = $cli;
        $this->di = $di;
        $this->options = array_merge($options, $config);

        declare (ticks = 100);

        // Install signal handlers
        pcntl_signal(SIGHUP, array($this, 'signal'));
        pcntl_signal(SIGINT, array($this, 'signal'));
        pcntl_signal(SIGTERM, array($this, 'signal'));
        pcntl_signal(SIGCHLD, array($this, 'signal'));
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
    public function attach($arguments = null) {

        if (!$this->has('appname')) {
            throw new Exception("Must set appname in order to run daemon.", 500);
        }

        if (!$this->has('appdir')) {
            throw new Exception("Must set appdir in order to run daemon.", 500);
        }

        $appName = $this->get('appname');
        $appDir = $this->get('appdir');

        $appID = strtolower($appName);
        $pidFile = paths($appDir, "{$appID}.pid");
        $runFile = paths('/var/run', "{$appID}.pid");

        $this->lock = new Lock($pidFile, $runFile);

        // Basic configuration

        $appName = $this->get('appname');
        $appDescription = $this->get('appdescription');
        $appNamespace = $this->get('appnamespace');

        // Logging

        $appLogLevel = $this->get('loglevel', 7);
        $this->logLevel = $appLogLevel;

        // Set up app

        // Get app class name
        $appClassName = ucfirst($appName);
        if (!is_null($appNamespace)) {
            $appClassName = "\\{$appNamespace}\\{$appClassName}";
        }

        // Prepare global CLI

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

        $this->di->get($appClassName);

        // Parse CLI
        $args = $this->cli->parse($arguments, true);
        $this->set('args', $args);

        $command = $args->getCommand();
        $sysDaemonize = $this->get('daemonize', true);
        if (!$sysDaemonize) {
            $command = 'start';
        }

        $this->log(Daemon::LOG_L_APP, "App has loaded.");
        die();

        $exitCode = null;
        switch ($command) {

            // Install symlink
            case 'install':
                break;

            // Stop or restart daemon
            case 'stop':
            case 'restart':

                // Check if pid file is currently running
                $runPid = self::getPid($pidFile);
                $isRunning = self::isRunning($runPid);

                if ($isRunning) {

                    // Stop it
                    posix_kill($runPid, SIGTERM);
                    sleep(1);

                    // Check if it's still running
                    if (running($runPid)) {

                        // Kill it harder
                        posix_kill($runPid, SIGKILL);
                        sleep(1);

                        // Check if it's still running
                        if (running($runPid)) {
                            $this->log(Daemon::LOG_L_FATAL, ' - unable to store daemon');
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
                    $this->log(Daemon::LOG_L_THREAD, ' - restarting...');
                }

            // Start daemon
            case 'start':

                // Check for currently running instance
                $runConcurrent = $this->get('appConcurrent', false);
                if (!$runConcurrent) {
                    // Check locks
                    $runPid = getPid($pidFile);
                    if (running($runPid)) {
                        $watchdog = $args->getOpt('watchdog');
                        $code = $watchdog ? 200 : 500;

                        $this->log(Daemon::LOG_L_FATAL, " - already running");
                        return 0;
                    }
                }

                // Running user
                $uid = posix_geteuid();
                $user = posix_getpwuid($uid);
                $this->set('user', $user);

                // Make sure we can do our things
                $sysUser = $this->get('sysRunAsUser', null);
                $sysGroup = $this->get('sysRunAsGroup', null);
                if ($sysUser || $sysGroup) {
                    if ($user != 'root') {
                        $this->log(Daemon::LOG_L_FATAL, ' - must be running as root to setegid() or seteuid()');
                        return 1;
                    }
                }

                $daemon = new Daemon();
                $daemon->getInstance();

                // Daemonize
                if ($sysDaemonize) {

                    $realm = $daemon->fork('daemon', true, $pidFile);
                    $daemon->realm = $realm;

                    // Console returns 0
                    if ($realm == 'console') {
                        $this->log(Daemon::LOG_L_THREAD, " - parent exited normally", Daemon::LOG_O_SHOWPID);
                        return 0;
                    }

                    $this->set('logtoscreen', false);

                } else {

                    $this->log(Daemon::LOG_L_THREAD, "Will not go into background", Daemon::LOG_O_SHOWPID);
                    $daemon->realm = 'daemon';
                }

                // Daemon returns execution to the main file
                $daemon->daemonPid = posix_getpid();

                // Invoking user
                $iusername = trim(shell_exec('logname'));
                $iuser = posix_getpwnam($iusername);
                $this->set('iuser', $iuser);

                // Current terminal name
                $terminal = posix_ttyname(STDOUT);
                $terminal = str_replace('/dev/', '', $terminal);
                $this->set('itty', $terminal);

                // Coordinate fleet
                $sysCoordinate = $this->get('sysCoordinate', false);
                if ($sysCoordinate) {
                    $daemon->coordinate();
                }

                $sysMode = $this->get('sysMode', self::MODE_SINGLE);
                switch ($sysMode) {
                    case self::MODE_SINGLE:

                        // Run app
                        $daemon->runApp();

                        break;

                    case self::MODE_FLEET:

                        // Launch and maintain fleet
                        $daemon->loiter();

                        break;
                }

                // Dismiss payload
                if ($sysCoordinate) {
                    $daemon->dismiss();
                }

                // Pipe exit code to wrapper file
                $exitCode = $daemon->exit;

                break;

            default:
                $exitHandled = null;

                // Hand off control to app
                if (method_exists($appClassName, 'cli')) {
                    $exitHandled = $appClassName::cli($args);
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
     * Get an instance of the app
     *
     * @return App
     */
    protected function getInstance() {
        if (!($this->instance instanceof App)) {
            $appName = $this->get('appName', null);
            $appNamespace = $this->get('appNamespace', null);

            // Run App
            $appClassName = ucfirst($appName);
            if (!is_null($appNamespace)) {
                $appClassName = "\\{$appNamespace}\\{$appClassName}";
            }

            $this->instance = new $appClassName();
        }
        return $this->instance;
    }

    /**
     * Instantiate and coordinate
     *
     * If 'sysCoordinate' is true, we need to create an instance of the payload
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
     * Dismiss coordinator
     *
     * @internal POST LOITER
     */
    protected function dismiss() {
        $this->getInstance();

        if (!is_null($this->instance) && method_exists($this->instance, 'dismiss')) {
            $this->instance->dismiss();
        }
    }

    /**
     * Loiter, launching fleet and waiting for them to land
     *
     * @return void
     */
    protected function loiter() {
        $this->log(Daemon::LOG_L_THREAD, " Entering loiter cycle for fleet", Daemon::LOG_O_SHOWPID | Daemon::LOG_O_NONEWLINE);

        // Sleep for 2 seconds
        for ($i = 0; $i < 2; $i++) {
            $this->log(Daemon::LOG_L_THREAD, '.', Daemon::LOG_O_NONEWLINE);
            sleep(1);
        }
        $this->log(Daemon::LOG_L_THREAD, '');

        $this->exitMode = $this->get('exitMode', 'success');

        $maxFleetSize = $this->get('sysFleet', 1);
        $this->log(Daemon::LOG_L_THREAD, " Launching fleet with {$maxFleetSize} workers", Daemon::LOG_O_SHOWPID);
        do {

            // Launch workers until the fleet is deployed
            if ($this->get('launching', true) && $maxFleetSize) {
                do {
                    $fleetSize = $this->fleetSize();
                    if ($fleetSize >= $maxFleetSize) {
                        break;
                    }
                    $launched = $this->launch();

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
                            $this->log(Daemon::LOG_L_THREAD, "  Failed to launch worker, moving on to cleanup", Daemon::LOG_O_SHOWPID);
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
            $this->log(Daemon::LOG_L_THREAD, "  Reaping fleet, currently {$fleetSize} outstanding, launching is {$launching}", Daemon::LOG_O_SHOWPID);

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
     */
    protected function launch() {
        $this->log(Daemon::LOG_L_THREAD, " Launching fleet worker", Daemon::LOG_O_SHOWPID);

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

        $exitCode = $this->runApp();
        exit($exitCode);
    }

    /**
     * Run application
     *
     * @internal POST FORK, POST FLEET
     */
    protected function runApp() {
        $this->getInstance();

        try {
            $runSuccess = $this->instance->run();
            unset($this->instance);
        } catch (Exception $ex) {
            $exitMessage = $ex->getMessage();
            $exitFile = $ex->getFile().':'.$ex->getLine();
            $this->log(Daemon::LOG_L_FATAL, "App Exception: {$exitMessage} {$exitFile}", Daemon::LOG_O_SHOWPID);
            return 1;
        }

        $this->log(Daemon::LOG_L_THREAD, " App exited with status: {$runSuccess}", Daemon::LOG_O_SHOWPID);

        // If this was not a controlled exit
        $exitCode = 0;
        switch ($runSuccess) {
            case self::APP_EXIT_EXIT:
                $this->log(Daemon::LOG_L_THREAD, " Halting from error condition...", Daemon::LOG_O_SHOWPID);
                $exitCode = 8;
                break;

            case self::APP_EXIT_HALT:
                $this->log(Daemon::LOG_L_THREAD, " Halting from normal operation...", Daemon::LOG_O_SHOWPID);
                $exitCode = 0;
                break;

            case self::APP_EXIT_RESTART:
                $this->log(Daemon::LOG_L_THREAD, " Gracefully exiting (cron restart)...", Daemon::LOG_O_SHOWPID);
                $exitCode = 2;
                break;

            case self::APP_EXIT_RELOAD:
            default:
                $this->log(Daemon::LOG_L_THREAD, " Preparing to reload...", Daemon::LOG_O_SHOWPID);
                $exitCode = 1;
                break;
        }
        return $exitCode;
    }

    /**
     * Fork into the background
     *
     * @param string $mode return realm label provider
     * @param string|boolean $lock optional. false gives no lock protection, anything
     *    else is treated as the path to a pidfile.
     */
    protected function fork($mode, $daemon = false, $lock = false) {
        //declare (ticks = 1);

        $modes = array(
            'daemon' => array(
                'parent' => 'console',
                'child' => 'daemon'
            ),
            'fleet' => array(
                'parent' => 'daemon',
                'child' => 'worker'
            )
        );
        if (!array_key_exists($mode, $modes)) {
            return false;
        }

        // Fork
        $pid = pcntl_fork();

        // Realm splitting
        if ($pid > 0) {

            $realm = val('parent', $modes[$mode]);

            // Parent
            $this->log(Daemon::LOG_L_THREAD, " Parent ({$realm})", Daemon::LOG_O_SHOWPID);

            // Record child PID
            $childRealm = val('child', $modes[$mode]);
            $this->children[$pid] = $childRealm;

            // Return as parent
            return $realm;
        } else if ($pid == 0) {

            $this->realm = val('child', $modes[$mode]);

            // Child
            $this->log(Daemon::LOG_L_THREAD, " Child ({$this->realm})", Daemon::LOG_O_SHOWPID);

            // Lock it up
            if ($lock) {
                $this->log(Daemon::LOG_L_THREAD, " - locking child process", Daemon::LOG_O_SHOWPID);
                $pidFile = $lock;
                $locked = lock($pidFile);
                if (!$locked) {
                    $this->log(Daemon::LOG_L_WARN, "Unable to lock forked process", Daemon::LOG_O_SHOWPID);
                    exit;
                }
            }

            $this->log(Daemon::LOG_L_THREAD, " Configuring child process ({$this->realm})", Daemon::LOG_O_SHOWPID);

            // Detach
            $this->log(Daemon::LOG_L_THREAD, "  - detach from console", Daemon::LOG_O_SHOWPID);
            if (posix_setsid() == -1) {
                $this->log(Daemon::LOG_L_THREAD, " Unable to detach from the terminal window", Daemon::LOG_O_SHOWPID);
                exit;
            }

            // Tell init about our pid
            if ($daemon) {
                $this->log(Daemon::LOG_L_THREAD, "  - run pid", Daemon::LOG_O_SHOWPID);
                file_put_contents_atomic($this->get('appRunfile'), $this->daemonPid);
            }

            // SETGID/SETUID
            $sysGroup = $this->get('sysRunAsGroup', null);
            if (!is_null($sysGroup)) {

                $sysGroupInfo = posix_getgrnam($sysGroup);
                if (is_array($sysGroupInfo)) {
                    $sysGID = val('gid', $sysGroupInfo, null);
                    if (!is_null($sysGID)) {
                        $sysSetegid = posix_setegid($sysGID);
                        $sysSetegid = $sysSetegid ? 'success' : 'failed';
                    }
                    $this->log(Daemon::LOG_L_THREAD, "  - setegid... {$sysSetegid}", Daemon::LOG_O_SHOWPID);
                } else {
                    $this->log(Daemon::LOG_L_THREAD, "  - setegid, no such group '{$sysGroup}'");
                }
            }

            $sysUser = $this->get('sysRunAsUser', null);
            if (!is_null($sysUser)) {
                $sysUserInfo = posix_getpwnam($sysUser);
                if (is_array($sysUserInfo)) {
                    $sysUID = val('uid', $sysUserInfo, null);
                    if (!is_null($sysUID)) {
                        $sysSeteuid = posix_seteuid($sysUID);
                        $sysSeteuid = $sysSeteuid ? 'success' : 'failed';
                    }
                    $this->log(Daemon::LOG_L_THREAD, "  - seteuid... {$sysSeteuid}", Daemon::LOG_O_SHOWPID);
                } else {
                    $this->log(Daemon::LOG_L_THREAD, "  - seteuid, no such user '{$sysUser}'");
                }
            }

            // Close resources
            //$this->log(Daemon::LOG_L_THREAD, "  - close fds", Daemon::LOG_O_SHOWPID);
            //fclose(STDIN);
            //fclose(STDOUT);
            //fclose(STDERR);
            // Return as child
            return $this->realm;
        } else {

            // Failed
            $this->log(Daemon::LOG_L_FATAL, "  Failed to fork process", Daemon::LOG_O_SHOWPID);
            exit(1);
        }
    }

    /**
     * Catch signals
     *
     * @param integer $signal
     */
    public function signal($signal) {
        $this->log(Daemon::LOG_L_THREAD, "Caught signal '{$signal}'", Daemon::LOG_O_SHOWPID);

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
     * @return integer
     */
    public function fleetSize() {
        return sizeof($this->children);
    }

    /**
     * Recover a fleet worker
     *
     * @param integer $pid
     * @param
     */
    protected function land($pid, $status = null) {
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
            $this->log(Daemon::LOG_L_THREAD, "Landing fleet '{$workerType}' with PID {$pid} ({$fleetSize} still in the air)", Daemon::LOG_O_SHOWPID);
        }
    }

    /**
     * Kill all children and return
     *
     */
    protected function genocide() {
        static $killing = false;
        if (!$killing) {
            $this->log(Daemon::LOG_L_THREAD, "Shutting down fleet operations...", Daemon::LOG_O_SHOWPID);
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
     * @return DateTime
     */
    public static function time($time = 'now', $format = null) {
        $timezone = new \DateTimeZone('utc');

        if (is_null($format)) {
            $date = new \DateTime($time, $timezone);
        } else {
            $date = \DateTime::createFromFormat($format, $time, $timezone);
        }

        return $date;
    }

    /**
     * Output to log (screen or file or both)
     *
     * @param integer $level event level
     * @param string $message
     * @param type $options
     */
    public function log($level, $message, $options = 0) {
        $format = '';
        $level = (int)$level;
        $logPriority = $this->loggerPriority($level);

        $context = [
            'priority' => $logPriority,
            'pid' => posix_getpid(),
            'time' => Daemon::time('now')->format('Y-m-d H:i:s'),
            'message' => $message
        ];

        if ($this->logLevel & $level || $this->logLevel == -1) {
            if ($options & Daemon::LOG_O_SHOWPID) {
                $format .= "[{pid}]";
            }

            if ($options & Daemon::LOG_O_SHOWTIME) {
                $format .= "[{time}]";
            }

            // Pad output if there are tags
            if (strlen($format)) {
                $format .= ' ';
            }

            $format .= '{message}';
            if (!($options & Daemon::LOG_O_NONEWLINE)) {
                $format .= "\n";
            }

            $canLogToPersist = $this->get('logtopersist', true);
            $canLogToScreen = $this->get('logtoscreen', true);

            if ($canLogToPersist) {
                $this->getLogger()->log($logPriority, $format, $context);
            }

            if (STDOUT) {
                // Allow echoing too
                if ($canLogToScreen || !$canLogToPersist || ($canLogToPersist && ($options & Daemon::LOG_O_ECHO))) {
                    echo $format;
                }
            }
        }
    }

    /**
     * Get log level
     *
     * @param integer $level
     * @retun string
     */
    protected function loggerPriority($level) {
        $levels = [
            self::LOG_L_FATAL   => LogLevel::ERROR,
            self::LOG_L_WARN    => LogLevel::WARNING,
            self::LOG_L_NOTICE  => LogLevel::NOTICE,
            self::LOG_L_INFO    => LogLevel::INFO,
            self::LOG_L_THREAD  => LogLevel::INFO,
            self::LOG_L_APP     => LogLevel::INFO,
            self::LOG_L_EVENT   => LogLevel::INFO,
            self::LOG_L_API     => LogLevel::INFO
        ];

        return $levels[$level] ?? LogLevel::INFO;
    }

}
