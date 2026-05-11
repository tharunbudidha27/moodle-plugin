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

    // 2026051200: v1.0 production-readiness cleanup.
    //   - Bump user_hash_salt to 64 chars (rule S9). One-time historical
    //     hash drift; documented in upgrade notes.
    //   - Drop local_fastpix_sync_state (reserved for ADR-003 with no ADR;
    //     unused schema removed per the v1.0 review N3 finding).
    //   - Seed default_access_policy and max_resolution config rows so
    //     existing installs pick up the upload-defaults UX.
    //   - purge_soft_deleted_assets task auto-registered via db/tasks.php
    //     on upgrade; no schema change required.
    if ($oldversion < 2026051200) {
        $salt = (string)get_config('local_fastpix', 'user_hash_salt');
        if (strlen($salt) < 64) {
            set_config('user_hash_salt', random_string(64), 'local_fastpix');
        }

        $sync_table = new xmldb_table('local_fastpix_sync_state');
        if ($dbman->table_exists($sync_table)) {
            $dbman->drop_table($sync_table);
        }

        if (get_config('local_fastpix', 'default_access_policy') === false) {
            set_config('default_access_policy', 'private', 'local_fastpix');
        }
        if (get_config('local_fastpix', 'max_resolution') === false) {
            set_config('max_resolution', '1080p', 'local_fastpix');
        }

        upgrade_plugin_savepoint(true, 2026051200, 'local', 'fastpix');
    }

    return true;
}
