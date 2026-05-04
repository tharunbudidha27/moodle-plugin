<?php
namespace local_fastpix\service;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;

defined('MOODLE_INTERNAL') || die();

class jwt_signing_service_test extends \advanced_testcase {

    private string $private_pem = '';
    private string $public_pem = '';
    private const KID = 'test-kid';

    public function setUp(): void {
        $this->resetAfterTest();

        $resource = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($resource, 'failed to generate test RSA key');

        openssl_pkey_export($resource, $private_pem);
        $details = openssl_pkey_get_details($resource);

        $this->private_pem = $private_pem;
        $this->public_pem = $details['key'];

        set_config('signing_key_id', self::KID, 'local_fastpix');
        set_config('signing_private_key', base64_encode($this->private_pem), 'local_fastpix');
    }

    private function decode_segments(string $jwt): array {
        $parts = explode('.', $jwt);
        $this->assertCount(3, $parts, 'JWT must have three segments');
        $b64url_decode = static fn(string $s): string =>
            base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
        $header = json_decode($b64url_decode($parts[0]), true);
        $payload = json_decode($b64url_decode($parts[1]), true);
        return [$header, $payload, $parts];
    }

    // --- Config-missing branches -----------------------------------------

    public function test_sign_for_playback_with_missing_kid_throws_signing_key_missing(): void {
        set_config('signing_key_id', '', 'local_fastpix');
        set_config('signing_private_key', 'abc', 'local_fastpix');

        try {
            (new jwt_signing_service())->sign_for_playback('pb-1');
            $this->fail('expected signing_key_missing');
        } catch (\local_fastpix\exception\signing_key_missing $e) {
            $this->assertStringContainsString('config_empty', $e->getMessage() . ' ' . (string)$e->a);
        }
    }

    public function test_sign_for_playback_with_missing_pem_throws_signing_key_missing(): void {
        set_config('signing_key_id', 'kid-1', 'local_fastpix');
        set_config('signing_private_key', '', 'local_fastpix');

        try {
            (new jwt_signing_service())->sign_for_playback('pb-1');
            $this->fail('expected signing_key_missing');
        } catch (\local_fastpix\exception\signing_key_missing $e) {
            $this->assertStringContainsString('config_empty', $e->getMessage() . ' ' . (string)$e->a);
        }
    }

    public function test_sign_for_playback_with_invalid_base64_throws_signing_key_missing(): void {
        set_config('signing_key_id', 'kid-1', 'local_fastpix');
        set_config('signing_private_key', '@@@invalid', 'local_fastpix');

        try {
            (new jwt_signing_service())->sign_for_playback('pb-1');
            $this->fail('expected signing_key_missing');
        } catch (\local_fastpix\exception\signing_key_missing $e) {
            $this->assertStringContainsString('invalid_base64', $e->getMessage() . ' ' . (string)$e->a);
        }
    }

    // --- Roundtrip / shape ------------------------------------------------

    public function test_sign_for_playback_returns_valid_three_segment_jwt(): void {
        $jwt = (new jwt_signing_service())->sign_for_playback('pb-1');
        $this->assertCount(3, explode('.', $jwt));
    }

    public function test_sign_for_playback_payload_has_correct_aud_format(): void {
        $jwt = (new jwt_signing_service())->sign_for_playback('pb-xyz');
        [, $payload] = $this->decode_segments($jwt);
        $this->assertSame('media:pb-xyz', $payload['aud']);
    }

    public function test_sign_for_playback_uses_rs256_in_header(): void {
        $jwt = (new jwt_signing_service())->sign_for_playback('pb-1');
        [$header] = $this->decode_segments($jwt);
        $this->assertSame('RS256', $header['alg']);
    }

    public function test_sign_for_playback_kid_in_header_matches_kid_in_payload(): void {
        $jwt = (new jwt_signing_service())->sign_for_playback('pb-1');
        [$header, $payload] = $this->decode_segments($jwt);
        $this->assertSame($header['kid'], $payload['kid']);
        $this->assertSame(self::KID, $header['kid']);
    }

    public function test_sign_for_playback_default_ttl_is_300(): void {
        $jwt = (new jwt_signing_service())->sign_for_playback('pb-1');
        [, $payload] = $this->decode_segments($jwt);
        $this->assertSame(300, (int)$payload['exp'] - (int)$payload['iat']);
    }

    public function test_sign_for_playback_custom_ttl_is_honored(): void {
        $jwt = (new jwt_signing_service())->sign_for_playback('pb-1', 60);
        [, $payload] = $this->decode_segments($jwt);
        $this->assertSame(60, (int)$payload['exp'] - (int)$payload['iat']);
    }

    public function test_sign_for_playback_signature_verifies_with_public_key(): void {
        $jwt = (new jwt_signing_service())->sign_for_playback('pb-verify');

        // Canonical roundtrip via firebase/php-jwt with the public key.
        $decoded = JWT::decode($jwt, new Key($this->public_pem, 'RS256'));
        $this->assertSame('media:pb-verify', $decoded->aud);

        // Belt-and-braces openssl_verify on the raw segments.
        $parts = explode('.', $jwt);
        $signing_input = $parts[0] . '.' . $parts[1];
        $signature = base64_decode(strtr($parts[2], '-_', '+/')
            . str_repeat('=', (4 - strlen($parts[2]) % 4) % 4));
        $verified = openssl_verify($signing_input, $signature, $this->public_pem, OPENSSL_ALGO_SHA256);
        $this->assertSame(1, $verified);
    }

    // --- Constants --------------------------------------------------------

    public function test_token_ttl_seconds_returns_300(): void {
        $this->assertSame(300, (new jwt_signing_service())->token_ttl_seconds());
    }

    // --- Redaction canary -------------------------------------------------

    public function test_redaction_canary_no_pem_or_jwt_in_logs(): void {
        $log_buffer = '';
        $original_error_log = ini_get('error_log');
        $tmp = tempnam(sys_get_temp_dir(), 'jwtlog_');
        ini_set('error_log', $tmp);

        try {
            (new jwt_signing_service())->sign_for_playback('pb-canary');
            $log_buffer = (string)file_get_contents($tmp);
        } finally {
            ini_set('error_log', $original_error_log);
            @unlink($tmp);
        }

        $this->assertDoesNotMatchRegularExpression('/eyJ[A-Za-z0-9_-]{10,}/', $log_buffer);
        $this->assertStringNotContainsString('-----BEGIN', $log_buffer);
        $this->assertStringNotContainsString($this->private_pem, $log_buffer);
    }
}
