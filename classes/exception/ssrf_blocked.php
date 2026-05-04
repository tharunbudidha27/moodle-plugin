<?php
namespace local_fastpix\exception;

defined('MOODLE_INTERNAL') || die();

class ssrf_blocked extends \moodle_exception {
    public function __construct(string $context = '') {
        parent::__construct('ssrf_blocked', 'local_fastpix', '', $context);
    }
}
