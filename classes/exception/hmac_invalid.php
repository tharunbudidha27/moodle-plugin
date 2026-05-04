<?php
namespace local_fastpix\exception;

defined('MOODLE_INTERNAL') || die();

class hmac_invalid extends \moodle_exception {
    public function __construct(string $context = '') {
        parent::__construct('hmac_invalid', 'local_fastpix', '', $context);
    }
}
