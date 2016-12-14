<?php

/**
 * @license MIT
 * @copyright 2016 Tim Gunter
 */

namespace Garden\Daemon;

/**
 * Daemon app base class
 *
 * @author Tim Gunter <tim@vanillaforums.com>
 * @package garden-daemon
 */
interface App {

    public function run();

}