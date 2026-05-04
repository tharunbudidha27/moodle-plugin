<?php
namespace local_fastpix\exception;

defined('MOODLE_INTERNAL') || die();

class gateway_unavailable extends \moodle_exception {
    public function __construct(string $context = '') {
        parent::__construct('gateway_unavailable', 'local_fastpix', '', $context);
    }
}
