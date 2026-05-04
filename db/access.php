<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'local/fastpix:configurecredentials' => [
        'riskbitmask'  => RISK_CONFIG | RISK_PERSONAL,
        'captype'      => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes'   => ['manager' => CAP_ALLOW],
    ],
];
