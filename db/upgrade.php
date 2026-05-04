<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_fastpix_upgrade($oldversion) {
    global $DB;
    // No upgrade steps for v1.0 (fresh install).
    return true;
}
