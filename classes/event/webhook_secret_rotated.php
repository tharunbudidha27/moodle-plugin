<?php
namespace local_fastpix\event;

defined('MOODLE_INTERNAL') || die();

/**
 * Audit event fired when an admin pastes a new webhook signing secret
 * (and a previous value existed). Lets ops trace rotation history via
 * the standard log without exposing any secret material in the event.
 */
class webhook_secret_rotated extends \core\event\base {

    protected function init() {
        $this->data['crud']        = 'u';
        $this->data['edulevel']    = self::LEVEL_OTHER;
        $this->data['objecttable'] = null;
    }

    public static function get_name() {
        return get_string('event_webhook_secret_rotated', 'local_fastpix');
    }

    public function get_description() {
        $when = (int)($this->other['rotated_at'] ?? 0);
        return 'Webhook signing secret rotated at ' . userdate($when);
    }
}
