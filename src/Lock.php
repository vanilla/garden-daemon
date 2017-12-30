<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Garden\Daemon;

/**
 * Daemon lock manager
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package garden-daemon
 */
class Lock {

    protected $pidFile;

    public function __construct($pidFile) {
        $this->pidFile = $pidFile;
    }

    /**
     * Lock
     *
     * @oaram integer $pid optional.
     * @return boolean
     */
    public function lock($pid = null) {
        if ($this->isLocked($this->pidFile)) {
            return false;
        }

        $myPid = $pid ?? getmypid();
        $pidDir = dirname($this->pidFile);
        if (!is_dir($pidDir)) {
            @mkdir($pidDir, 0744, true);
        }
        file_put_contents($this->pidFile, $myPid);
        return true;
    }

    /**
     * Unlock
     *
     * @return boolean
     */
    public function unlock() {
        @unlink($this->pidFile);
        return true;
    }

    /**
     * Check if this lockFile corresponds to a locked process
     *
     * @param boolean $recover
     * @return boolean
     */
    public function isLocked($recover = true) {
        $myPid = getmypid();
        if (!file_exists($this->pidFile)) {
            return false;
        }

        $lockPid = trim(file_get_contents($this->pidFile));

        // This is my lockfile, nothing to do
        if ($myPid == $lockPid) {
            return false;
        }

        // Is the PID running?
        $isRunning = $this->isProcessRunning($lockPid);

        // No? Unlock and return Locked=false
        if (!$isRunning) {
            if ($recover) {
                $this->unlock($this->pidFile);
            }
            return false;
        }

        // Someone else is already running
        return true;
    }

    /**
     * Check if a pid is running
     *
     * @param string $pid
     * @return boolean
     */
    public function isProcessRunning($pid) {
        if (!$pid) {
            return false;
        }

        // Is the PID running?
        $running = posix_kill($pid, 0);
        if (!$running) {
            return false;
        }

        // Did we have trouble pinging that PID?
        $psExists = !(bool)posix_get_last_error();

        return $psExists;
    }

    /**
     * Get agent pid
     *
     * @return integer|false
     */
    public function getRunningPID() {
        if (!file_exists($this->pidFile)) {
            return false;
        }

        $runPid = trim(file_get_contents($this->pidFile));
        if (!$runPid) {
            return false;
        }

        return $runPid;
    }

}