<?php
defined('MOODLE_INTERNAL') || die();

$definitions = [
    'asset' => [
        'mode'                   => cache_store::MODE_APPLICATION,
        'simplekeys'             => true,
        'simpledata'             => false,
        'persistent'             => true,
        'staticacceleration'     => true,
        'staticaccelerationsize' => 100,
        'ttl'                    => 60,
    ],
    'rate_limit' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 60,
    ],
    // Circuit breaker state. CRITICAL: must be in MUC (shared store, e.g. Redis)
    // for multi-FPM correctness. Document this requirement in README.
    'circuit_breaker' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        'ttl'        => 60,
    ],
    'upload_dedup' => [
        'mode'       => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => true,
        'ttl'        => 60,
    ],
];
