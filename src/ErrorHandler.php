<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Garden\Daemon;

use Garden\Container\Container;

/**
 * Daemon error handler
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package garden-daemon
 */
class ErrorHandler implements ErrorHandlerInterface {

    /**
     * List of handlers
     * @var array
     */
    protected $handlers;

    /**
     *
     * @var type
     */
    protected $di;

    /**
     * Constructor
     *
     */
    public function __construct(Container $di) {
        $this->di = $di;
        $this->handlers = [];
    }

    /**
     * Add an error handler
     *
     * @param \Garden\Daemon\callable $handler
     * @param int $errorMask
     */
    public function addHandler(callable $handler, $errorMask = E_ALL | E_STRICT) {
        $this->handlers[] = [
            'handler'       => $handler,
            'error_mask'    => $errorMask
        ];
    }

    /**
     * Remove handler
     *
     * @param \Callable $handler
     */
    public function removerHandler(callable $handler) {
        foreach ($this->handlers as $i => $oldHandler) {
            if ($handler == $oldHandler['handler']) {
                unset($this->handlers[$i]);
            }
        }
    }

    /**
     * Handle error
     *
     * @param int $errorNumber
     * @param string $message
     * @param string $file
     * @param int $line
     * @param array $context
     */
    public function error($errorNumber, $message, $file, $line, $context) {
        $errorReporting = error_reporting();
        $errorEnabled = (bool)($errorReporting & $errorNumber);

        // Ignore errors that are below the current error reporting level.
        if (!$errorEnabled) {
            return false;
        }

        $backtrace = debug_backtrace();
        throw new ErrorException($message, $errorNumber, $file, $line, $context, $backtrace);
    }

    /**
     * Handle exception
     *
     * @param \Throwable $exception
     */
    public function exception(\Throwable $exception) {
        if (count($this->handlers)) {

            foreach ($this->handlers as $handler) {
                $handlerMask = $handler['error_mask'];
                $errorEnabled = $exception->getCode() === 0 || (bool)($handlerMask & $exception->getCode());
                if ($errorEnabled) {
                    $context = method_exists($exception, 'getContext') ? $exception->getContext() : [];
                    $continue = $this->di->call($handler['handler'], [$exception->getCode(), $exception->getMessage(), $exception->getFile(), $exception->getLine(), $context]);
                    if ($continue === false) {
                        break;
                    }
                }
            }

        }
    }

}