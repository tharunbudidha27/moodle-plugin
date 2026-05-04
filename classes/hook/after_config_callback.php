<?php
namespace local_fastpix\hook;

defined('MOODLE_INTERNAL') || die();

/**
 * Replaces the legacy `local_fastpix_after_config()` callback.
 * Body is intentionally empty until Phase 2 wires up secret/signing-key
 * auto-bootstrap.
 */
class after_config_callback {

    public static function handle(\core\hook\after_config $hook): void {
        // intentionally empty until Phase 2
    }
}
