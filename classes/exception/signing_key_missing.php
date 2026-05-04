<?php
namespace local_fastpix\exception;

defined('MOODLE_INTERNAL') || die();

class signing_key_missing extends \moodle_exception {
    public function __construct(string $context = '') {
        parent::__construct('signing_key_missing', 'local_fastpix', '', $context);
    }
}
