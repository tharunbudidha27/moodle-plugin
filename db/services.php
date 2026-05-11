<?php
defined('MOODLE_INTERNAL') || die();

// The 'description' fields below are English literals, NOT get_string() calls.
// Empirical audit on 2026-05-05 of 23 mod/*/db/services.php files in Moodle 4.5
// core shows zero use of get_string() in description — Moodle's web-services UI
// does not pass description through the lang loader.

$functions = [
    'local_fastpix_create_upload_session' => [
        'classname'    => '\local_fastpix\external\create_upload_session',
        'methodname'   => 'execute',
        'description'  => 'Create a direct upload session.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:uploadmedia',
    ],
    'local_fastpix_create_url_pull_session' => [
        'classname'    => '\local_fastpix\external\create_url_pull_session',
        'methodname'   => 'execute',
        'description'  => 'Create a URL-pull ingest session.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:uploadmedia',
    ],
    'local_fastpix_get_upload_status' => [
        'classname'    => '\local_fastpix\external\get_upload_status',
        'methodname'   => 'execute',
        'description'  => 'Poll the status of an upload session.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'mod/fastpix:uploadmedia',
    ],
    'local_fastpix_test_connection' => [
        'classname'    => '\local_fastpix\external\test_connection',
        'methodname'   => 'execute',
        'description'  => 'Probe FastPix reachability from the admin settings page.',
        'type'         => 'read',
        'ajax'         => true,
        'capabilities' => 'local/fastpix:configurecredentials',
    ],
    'local_fastpix_send_test_event' => [
        'classname'    => '\local_fastpix\external\send_test_event',
        'methodname'   => 'execute',
        'description'  => 'Fire a synthetic signed webhook event into the local processor for diagnostics.',
        'type'         => 'write',
        'ajax'         => true,
        'capabilities' => 'local/fastpix:configurecredentials',
    ],
];
