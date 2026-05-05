<?php
namespace local_fastpix\external;

defined('MOODLE_INTERNAL') || die();

/**
 * External function: create a direct file upload session against FastPix.
 *
 * Per architecture doc §3.3 (Direct upload session) and §15.4 (upload service).
 * Per @upload-service agent: this endpoint is responsible for capability
 * + sesskey + login enforcement. The service layer does NOT enforce them.
 *
 * Registered in db/services.php as 'local_fastpix_create_upload_session'.
 * Capability: mod/fastpix:uploadmedia (per ADR-012, owned by mod_fastpix).
 */
class create_upload_session extends \core_external\external_api {

    public static function execute_parameters(): \core_external\external_function_parameters {
        return new \core_external\external_function_parameters([
            'filename' => new \core_external\external_value(
                PARAM_TEXT,
                'Original filename of the upload (used for dedup hashing only)',
                VALUE_REQUIRED
            ),
            'size' => new \core_external\external_value(
                PARAM_INT,
                'File size in bytes (used for dedup hashing only)',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Create a direct upload session.
     *
     * @param string $filename Original filename
     * @param int    $size     File size in bytes
     * @return array{session_id:int,upload_id:string,upload_url:string,expires_at:int,deduped:bool}
     */
    public static function execute(string $filename, int $size): array {
        global $USER;

        // 1. Validate parameters first (throws invalid_parameter_exception).
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['filename' => $filename, 'size' => $size]
        );

        // 2. Authenticate + authorize.
        $context = \context_system::instance();
        require_login(null, false);
        require_sesskey();
        require_capability('mod/fastpix:uploadmedia', $context);

        // 3. Delegate to service layer.
        $result = \local_fastpix\service\upload_service::instance()
            ->create_file_upload_session(
                (int)$USER->id,
                [
                    'filename' => $params['filename'],
                    'size'     => $params['size'],
                ]
            );

        // 4. Return matches execute_returns() structure.
        return [
            'session_id' => (int)$result->session_id,
            'upload_id'  => (string)$result->upload_id,
            'upload_url' => (string)$result->upload_url,
            'expires_at' => (int)$result->expires_at,
            'deduped'    => (bool)$result->deduped,
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
            'upload_url' => new \core_external\external_value(
                PARAM_RAW,
                'Signed upload URL (Google Cloud Storage); empty for URL pull'
            ),
            'expires_at' => new \core_external\external_value(
                PARAM_INT,
                'Unix timestamp at which upload_url expires'
            ),
            'deduped' => new \core_external\external_value(
                PARAM_BOOL,
                'True if this session was returned from the 60s dedup cache'
            ),
        ]);
    }
}
