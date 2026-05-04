<?php
namespace local_fastpix\exception;

defined('MOODLE_INTERNAL') || die();

class rate_limit_exceeded extends \moodle_exception {
    public function __construct(string $context = '') {
        parent::__construct('rate_limit_exceeded', 'local_fastpix', '', $context);
    }
}
