<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Garden\Daemon;

/**
 * Daemon error handler interface
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package garden-daemon
 */
interface ErrorHandlerInterface {

    /**
     * Error handling callback
     * 
     * @param int $errorNumber
     * @param string $message
     * @param string $file
     * @param int $line
     */
    public function error($errorNumber, $message, $file, $line);

}