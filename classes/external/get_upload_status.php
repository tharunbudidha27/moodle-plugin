<?php
namespace local_fastpix\external;

defined('MOODLE_INTERNAL') || die();

/**
 * External function: poll the status of an upload session.
 *
 * Read-only. Returns the current state of the upload session row including
 * whether the FastPix media UUID has been linked yet (populated when the
 * video.media.created webhook lands).
 *
 * Per architecture doc §15.4 (upload service) and @security-compliance:
 * ownership is enforced in the service layer's SQL — a user cannot poll
 * another user's session even if they hold the :uploadmedia capability.
 *
 * Registered in db/services.php as 'local_fastpix_get_upload_status'.
 * type=read; capability: mod/fastpix:uploadmedia (per ADR-012).
 * No sesskey required (read-only endpoint, idempotent, CSRF-safe).
 */
class get_upload_status extends \core_external\external_api {

    public static function execute_parameters(): \core_external\external_function_parameters {
        return new \core_external\external_function_parameters([
            'session_id' => new \core_external\external_value(
                PARAM_INT,
                'Local upload_session row id (returned from create_*_session)',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Get the status of an upload session.
     *
     * @param int $session_id Local upload_session row id
     * @return array{session_id:int,upload_id:string,state:string,fastpix_id:string,expires_at:int}
     * @throws \local_fastpix\exception\asset_not_found if not found OR not owned by caller
     */
    public static function execute(int $session_id): array {
        global $USER;

        // 1. Validate parameters first.
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['session_id' => $session_id]
        );

        // 2. Authenticate + authorize.
        // No sesskey: type=read, idempotent, CSRF-safe per Moodle convention.
        $context = \context_system::instance();
        require_login(null, false);
        require_capability('mod/fastpix:uploadmedia', $context);

        // 3. Delegate to service. Ownership check is enforced in the SQL.
        $result = \local_fastpix\service\upload_service::instance()
            ->get_status((int)$params['session_id'], (int)$USER->id);

        // 4. Return matches execute_returns() structure.
        return [
            'session_id' => (int)$result->session_id,
            'upload_id'  => (string)$result->upload_id,
            'state'      => (string)$result->state,
            'fastpix_id' => (string)$result->fastpix_id,
            'expires_at' => (int)$result->expires_at,
        ];
    }

    public static function execute_returns(): \core_external\external_single_structure {
        return new \core_external\external_single_structure([
            'session_id' => new \core_external\external_value(
                PARAM_INT,
                'Local upload session row id'
            ),
            'upload_id' => new \core_external\external_value(
                PARAM_TEXT,
                'FastPix upload ID (UUID)'
            ),
            'state' => new \core_external\external_value(
                PARAM_ALPHA,
                'Current state of the session: pending, linked, etc.'
            ),
            'fastpix_id' => new \core_external\external_value(
                PARAM_RAW,
                'FastPix Media ID (UUID); empty string until video.media.created webhook lands'
            ),
            'expires_at' => new \core_external\external_value(
                PARAM_INT,
                'Unix timestamp at which the session expires'
            ),
        ]);
    }
}
