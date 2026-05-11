<?php
namespace local_fastpix\service;

use Firebase\JWT\JWT;
use local_fastpix\exception\signing_key_missing;

defined('MOODLE_INTERNAL') || die();

class jwt_signing_service {

    private const TOKEN_TTL_SECONDS = 300;
    private const ISS = 'fastpix.io';

    public function sign_for_playback(string $playback_id, ?int $ttl = null): string {
        $kid = (string)get_config('local_fastpix', 'signing_key_id');
        $private_key_b64 = (string)get_config('local_fastpix', 'signing_private_key');

        if ($kid === '' || $private_key_b64 === '') {
            throw new signing_key_missing('config_empty');
        }

        $pem = base64_decode($private_key_b64, true);
        if ($pem === false) {
            throw new signing_key_missing('invalid_base64');
        }

        $now = time();
        $payload = [
            'kid' => $kid,
            'aud' => 'media:' . $playback_id,
            'iss' => self::ISS,
            // reserved; do NOT fill with userid — would violate S9 (raw userid in JWT payload).
            'sub' => '',
            'iat' => $now,
            'exp' => $now + ($ttl ?? self::TOKEN_TTL_SECONDS),
        ];

        return JWT::encode($payload, $pem, 'RS256', $kid);
    }

    public function token_ttl_seconds(): int {
        return self::TOKEN_TTL_SECONDS;
    }
}
