<?php
namespace local_fastpix\exception;

defined('MOODLE_INTERNAL') || die();

/**
 * Asset exists but is not in status='ready'. Caller should retry after a
 * webhook projection lands (status flips to ready) — until then, no JWT
 * can be signed for it.
 */
class asset_not_ready extends \moodle_exception {
    public function __construct(string $context = '') {
        parent::__construct('asset_not_ready', 'local_fastpix', '', $context);
    }
}
