<?php
namespace local_fastpix\external;

defined('MOODLE_INTERNAL') || die();

/**
 * External function: create a URL-pull ingest session against FastPix.
 *
 * FastPix downloads from the source URL on its own infrastructure rather
 * than the user uploading bytes directly. The signed upload_url returned
 * is empty (PARAM_RAW) — there is no GCS URL to PUT to.
 *
 * Per architecture doc §3.3 (URL pull) and §15.4 (upload service).
 * Per @upload-service agent: SSRF check happens in the service BEFORE
 * the gateway call. This endpoint does not duplicate the check.
 *
 * Registered in db/services.php as 'local_fastpix_create_url_pull_session'.
 * Capability: mod/fastpix:uploadmedia (per ADR-012, owned by mod_fastpix).
 */
class create_url_pull_session extends \core_external\external_api {

    public static function execute_parameters(): \core_external\external_function_parameters {
        return new \core_external\external_function_parameters([
            'source_url' => new \core_external\external_value(
                PARAM_URL,
                'Public HTTPS URL of the source video (FastPix will fetch from here)',
                VALUE_REQUIRED
            ),
        ]);
    }

    /**
     * Create a URL-pull session.
     *
     * @param string $source_url Public HTTPS URL FastPix will fetch from
     * @return array{session_id:int,upload_id:string,upload_url:string,expires_at:int,deduped:bool}
     */
    public static function execute(string $source_url): array {
        global $USER;

        // 1. Validate parameters first (throws invalid_parameter_exception).
        $params = self::validate_parameters(
            self::execute_parameters(),
            ['source_url' => $source_url]
        );

        // 2. Authenticate + authorize.
        $context = \context_system::instance();
        require_login(null, false);
        require_sesskey();
        require_capability('mod/fastpix:uploadmedia', $context);

        // 3. Delegate to service layer. SSRF allow-list runs INSIDE the service
        // BEFORE the gateway call (rule S6, @upload-service guardrail).
        // ssrf_blocked exceptions propagate to the caller as service errors.
        $result = \local_fastpix\service\upload_service::instance()
            ->create_url_pull_session(
                (int)$USER->id,
                $params['source_url']
            );

        // 4. Return matches execute_returns() structure.
        // upload_url is empty for URL-pull sessions — no GCS URL to PUT to.
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
                'Empty string for URL-pull sessions (FastPix fetches the source itself)'
            ),
            'expires_at' => new \core_external\external_value(
                PARAM_INT,
                'Unix timestamp at which the session expires'
            ),
            'deduped' => new \core_external\external_value(
                PARAM_BOOL,
                'True if this session was returned from the 60s dedup cache'
            ),
        ]);
    }
}
