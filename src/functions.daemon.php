<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package garden-daemon
 */

function daemonErrorHandler($errorNumber, $message, $file, $line, $arguments) {
    $errorReporting = error_reporting();
    // Ignore errors that are below the current error reporting level.
    if (($errorReporting & $errorNumber) != $errorNumber) {
        return false;
    }

    $backtrace = debug_backtrace();

    throw new \Garden\Daemon\ErrorException($message, $errorNumber, $file, $line, $arguments, $backtrace);
}

set_error_handler('daemonErrorHandler', E_ALL);