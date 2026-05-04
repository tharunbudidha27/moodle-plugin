<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook'     => \core\hook\after_config::class,
        'callback' => '\local_fastpix\hook\after_config_callback::handle',
    ],
];
