<?php
defined('MOODLE_INTERNAL') || die();

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
