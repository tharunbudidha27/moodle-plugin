<?php
namespace local_fastpix\exception;

defined('MOODLE_INTERNAL') || die();

class drm_not_configured extends \moodle_exception {
    public function __construct(string $context = '') {
        parent::__construct('drm_not_configured', 'local_fastpix', '', $context);
    }
}
