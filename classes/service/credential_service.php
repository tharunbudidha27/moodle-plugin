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
        $kid = (string)get_config('local_fastpix', 'signing_key_id');
        $pem = (string)get_config('local_fastpix', 'signing_private_key');
        if ($kid !== '' && $pem !== '') {
            return;
        }

        $response = ($this->gateway ?? \local_fastpix\api\gateway::instance())->create_signing_key();

        $new_kid = (string)($response->id ?? '');
        $new_pem = (string)($response->privateKey ?? '');

        set_config('signing_key_id', $new_kid, 'local_fastpix');
        set_config('signing_private_key', base64_encode($new_pem), 'local_fastpix');

        // Log only the kid; the private key never appears in any log line (S2).
        error_log(json_encode([
            'event' => 'credential.signing_key_bootstrapped',
            'id'    => $new_kid,
        ]));
    }
}
