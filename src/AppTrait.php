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
     *
     * @var \Garden\Cli\Cli
     */
    protected $cli;

    /**
     *
     */
    public function preflight(\Garden\Cli\Cli $cli) {
        $this->cli = $cli;
    }

}