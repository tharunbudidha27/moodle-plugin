<?php
defined('MOODLE_INTERNAL') || die();

// The 'description' fields below are English literals, NOT get_string() calls.
//
// REVIEW-2026-05-04 flagged this as an M9 lang-string violation. Empirical
// audit on 2026-05-05 of 23 mod/*/db/services.php files in Moodle 4.5 core
// (mod_quiz, mod_assign, mod_forum, et al.) shows zero use of get_string()
// in description — all use English literals. Moodle's web-services UI does
// not pass description through the lang loader, and core convention is to
// keep these as plain English. Flag closed as a documented no-op (T2.4).

$functions = [
    'local_fastpix_create_upload_session' => [
        'classname'   => '\local_fastpix\external\create_upload_session',
        'methodname'  => 'execute',
        'description' => 'Create a direct upload session.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/fastpix:uploadmedia',
    ],
    'local_fastpix_create_url_pull_session' => [
        'classname'   => '\local_fastpix\external\create_url_pull_session',
        'methodname'  => 'execute',
        'description' => 'Create a URL-pull ingest session.',
        'type'        => 'write',
        'ajax'        => true,
        'capabilities' => 'mod/fastpix:uploadmedia',
    ],
    'local_fastpix_get_upload_status' => [
        'classname'   => '\local_fastpix\external\get_upload_status',
        'methodname'  => 'execute',
        'description' => 'Poll the status of an upload session.',
        'type'        => 'read',
        'ajax'        => true,
        'capabilities' => 'mod/fastpix:uploadmedia',
    ],
];
