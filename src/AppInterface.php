<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Garden\Daemon;

/**
 * Daemon app interface
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package garden-daemon
 */
interface AppInterface {

    /**
     * Run just before parsing the CLI
     *
     * @param \Garden\Cli\Cli $cli
     */
    public function preflight(\Garden\Cli\Cli $cli);

    /**
     * The first thing we run after forking into our daemon
     *
     * @param \Garden\Cli\Args $args
     */
    public function initialize(\Garden\Cli\Args $args);

    /**
     * Main app scope
     *
     */
    public function run();

}