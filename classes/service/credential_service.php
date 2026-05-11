<?php
namespace local_fastpix\service;

defined('MOODLE_INTERNAL') || die();

class credential_service {

    private static ?self $instance = null;

    private ?\local_fastpix\api\gateway $gateway = null;

    private function __construct() {}

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function reset(): void {
        self::$instance = null;
    }

    /**
     * DI seam for tests — inject a mocked gateway. Production code uses
     * \local_fastpix\api\gateway::instance() lazily.
     */
    public function set_gateway(\local_fastpix\api\gateway $gateway): void {
        $this->gateway = $gateway;
    }

    public function apikey(): string {
        $value = (string)get_config('local_fastpix', 'apikey');
        if ($value === '') {
            throw new \moodle_exception(
                'credentials_missing', 'local_fastpix', '', 'apikey or apisecret not configured'
            );
        }
        return $value;
    }

    public function apisecret(): string {
        $value = (string)get_config('local_fastpix', 'apisecret');
        if ($value === '') {
            throw new \moodle_exception(
                'credentials_missing', 'local_fastpix', '', 'apikey or apisecret not configured'
            );
        }
        return $value;
    }

    /**
     * Bootstrap the local RS256 signing key on first call. Idempotent.
     * Stores the kid and a base64-encoded PEM in mdl_config_plugins.
     * NEVER logs the private key.
     */
    public function ensure_signing_key(): void {
        // Fast path: already minted, no lock needed.
        $kid = (string)get_config('local_fastpix', 'signing_key_id');
        $pem = (string)get_config('local_fastpix', 'signing_private_key');
        if ($kid !== '' && $pem !== '') {
            return;
        }

        // Concurrency: under PHP-FPM, two workers can both pass the check
        // above and both call create_signing_key — leaking one key on the
        // FastPix side. Use \core\lock to serialize first-time bootstrap.
        // Per REVIEW-2026-05-04 §4 (concurrency).
        $factory = \core\lock\lock_config::get_lock_factory('local_fastpix_signing_key');
        $lock = $factory->get_lock('ensure', 30);
        if (!$lock) {
            throw new \local_fastpix\exception\lock_acquisition_failed(
                'ensure_signing_key'
            );
        }

        try {
            // Double-check inside the lock: another worker may have just
            // bootstrapped while we were waiting. If so, nothing to do.
            $kid = (string)get_config('local_fastpix', 'signing_key_id');
            $pem = (string)get_config('local_fastpix', 'signing_private_key');
            if ($kid !== '' && $pem !== '') {
                return;
            }

            $response = ($this->gateway ?? \local_fastpix\api\gateway::instance())->create_signing_key();

            // FastPix wraps the response: {"success": true, "data": {"id": ..., "privateKey": ...}}
            // Unit-test mocks sometimes return the unwrapped shape; accept both.
            $payload = $response->data ?? $response;
            $new_kid = (string)($payload->id ?? '');
            $new_pem_field = (string)($payload->privateKey ?? '');

            if ($new_kid === '' || $new_pem_field === '') {
                throw new \local_fastpix\exception\signing_key_missing(
                    'gateway returned empty kid or privateKey field'
                );
            }

            // FastPix returns privateKey ALREADY base64-encoded. Some unit-test
            // mocks return a raw PEM string. Normalize so what we store is
            // exactly one base64 layer over a real PEM — which jwt_signing_service
            // can decode and feed straight into openssl_pkey_get_private().
            $decoded_once = base64_decode($new_pem_field, true);
            $looks_like_pem = $decoded_once !== false
                && str_contains($decoded_once, '-----BEGIN');
            $new_pem_b64 = $looks_like_pem
                ? $new_pem_field                      // already base64'd PEM — store as-is.
                : base64_encode($new_pem_field);      // raw PEM (test mock) — encode once.

            set_config('signing_key_id',         $new_kid,     'local_fastpix');
            set_config('signing_private_key',    $new_pem_b64, 'local_fastpix');
            set_config('signing_key_created_at', time(),       'local_fastpix');

            // Log only the kid; the private key never appears in any log line (S2).
            error_log(json_encode([
                'event' => 'credential.signing_key_bootstrapped',
                'id'    => $new_kid,
            ]));
        } finally {
            // Release MUST run even if create_signing_key threw, so the
            // next worker can retry instead of waiting 30s for stale lock.
            $lock->release();
        }
    }
}
