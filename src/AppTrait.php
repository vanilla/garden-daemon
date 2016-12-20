<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Garden\Daemon;

/**
 * Daemon app trait stub
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package garden-daemon
 */
trait AppTrait {

    /**
     * CLI Definition
     * @var \Garden\Cli\Cli
     */
    protected $cli;

    /**
     * CLI Arguments
     * @var \Garden\Cli\Args
     */
    protected $args;

    /**
     *
     */
    public function preflight(\Garden\Cli\Cli $cli) {
        $this->cli = $cli;
    }


    public function initialize(\Garden\Cli\Args $args) {
        $this->args = $args;
    }

}