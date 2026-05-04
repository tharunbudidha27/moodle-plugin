<?php
namespace local_fastpix\exception;

defined('MOODLE_INTERNAL') || die();

class gateway_not_found extends \moodle_exception {
    public function __construct(string $context = '') {
        parent::__construct('gateway_not_found', 'local_fastpix', '', $context);
    }
}
