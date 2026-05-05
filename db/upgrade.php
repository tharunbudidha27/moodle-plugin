<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_fastpix_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    // T3.5 (2026-05-05): retry-counter column for GDPR delete cap.
    if ($oldversion < 2026050504) {
        $table = new xmldb_table('local_fastpix_asset');
        $field = new xmldb_field(
            'gdpr_delete_attempts',
            XMLDB_TYPE_INTEGER,
            '10',
            null,
            XMLDB_NOTNULL,
            null,
            '0',
            'gdpr_delete_pending_at',
        );
        if (!$dbman->field_exists($table, $field)) {
            $dbman->add_field($table, $field);
        }

        upgrade_plugin_savepoint(true, 2026050504, 'local', 'fastpix');
    }

    return true;
}
