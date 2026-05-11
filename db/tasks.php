<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => '\local_fastpix\task\orphan_sweeper',
        'blocking'  => 0,
        'minute'    => '17',
        'hour'      => '3',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => '\local_fastpix\task\webhook_event_pruner',
        'blocking'  => 0,
        'minute'    => '23',
        'hour'      => '4',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => '\local_fastpix\task\asset_cleanup',
        'blocking'  => 0,
        'minute'    => '47',
        'hour'      => '4',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => '\local_fastpix\task\retry_gdpr_delete',
        'blocking'  => 0,
        'minute'    => '*/15',
        'hour'      => '*',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => '\local_fastpix\task\purge_soft_deleted_assets',
        'blocking'  => 0,
        'minute'    => '30',
        'hour'      => '2',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
    [
        'classname' => '\local_fastpix\task\signing_key_rotator',
        'blocking'  => 0,
        'minute'    => '11',
        'hour'      => '5',
        'day'       => '*',
        'dayofweek' => '*',
        'month'     => '*',
    ],
];
