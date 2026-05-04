<?php
namespace local_fastpix\exception;

defined('MOODLE_INTERNAL') || die();

class lock_acquisition_failed extends \moodle_exception {
    public function __construct(string $context = '') {
        parent::__construct('lock_acquisition_failed', 'local_fastpix', '', $context);
    }
}
