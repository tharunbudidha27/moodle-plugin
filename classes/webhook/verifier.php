<?php
namespace local_fastpix\webhook;

defined('MOODLE_INTERNAL') || die();

class verifier {

    private const HMAC_ALGO        = 'sha256';
    private const ROTATION_WINDOW  = 1800; // 30 minutes

    // Minimum acceptable length (bytes) for the configured webhook secret.
    private const MIN_SECRET_BYTES = 32;

    private static ?self $instance = null;

    private function __construct() {}

    public static function instance(): self {
        return self::$instance ??= new self();
    }

    public static function reset(): void {
        self::$instance = null;
    }

    /**
     * Verify a FastPix webhook signature.
     *
     * Returns true if the signature matches the current secret, or matches the
     * previous secret within the 30-minute rotation window. Returns false on
     * any failure — never throws (rule S7).
     */
    public function verify(string $raw_body, string $signature_header): bool {
        if (strlen($signature_header) < 1 || strlen($raw_body) < 1) {
            debugging('webhook signature verify: empty body or signature', DEBUG_DEVELOPER);
            return false;
        }

        $current = (string)get_config('local_fastpix', 'webhook_secret_current');
        if (strlen($current) < 1) {
            debugging('webhook signature verify: current secret not configured', DEBUG_DEVELOPER);
            return false;
        }
        if (strlen($current) < self::MIN_SECRET_BYTES) {
            $this->log_short_secret('current', strlen($current));
            return false;
        }

        if ($this->matches_either_format($raw_body, $current, $signature_header)) {
            return true;
        }

        $previous = (string)get_config('local_fastpix', 'webhook_secret_previous');
        $rotated_at = (int)get_config('local_fastpix', 'webhook_secret_rotated_at');
        if ($previous !== '' && ($rotated_at > 0) && (time() - $rotated_at) < self::ROTATION_WINDOW) {
            if (strlen($previous) < self::MIN_SECRET_BYTES) {
                $this->log_short_secret('previous', strlen($previous));
                return false;
            }
            if ($this->matches_either_format($raw_body, $previous, $signature_header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Compare the provided signature against the canonical FastPix shape:
     *
     *   keyBytes = base64_decode(SECRET)
     *   sig      = base64_encode(hmac_sha256(keyBytes, body))
     *
     * Empirically verified 2026-05-07 against the FastPix sandbox; matches
     * the Express reference verifier in FastPix's docs. Three legacy
     * fallbacks (raw-string secret, hex output, mixed) live behind
     * LOCAL_FASTPIX_DEBUG_VERIFIER so the test suite can drive synthetic
     * fixtures without enlarging the production attack surface.
     * Per rule S3, all compares use hash_equals.
     */
    private function matches_either_format(string $raw_body, string $secret, string $signature_header): bool {
        // FastPix canonical: secret is base64; output is base64.
        $decoded_secret = base64_decode($secret, true);
        if ($decoded_secret !== false && $decoded_secret !== '') {
            $raw_hmac = hash_hmac(self::HMAC_ALGO, $raw_body, $decoded_secret, true);
            if ($this->constant_time_compare(base64_encode($raw_hmac), $signature_header)) {
                return true;
            }
        }

        // Test-only fallbacks. Gated by a constant the production bootstrap
        // never defines; tests opt in by defining it before driving verify().
        if (defined('LOCAL_FASTPIX_DEBUG_VERIFIER') && LOCAL_FASTPIX_DEBUG_VERIFIER) {
            if ($decoded_secret !== false && $decoded_secret !== '') {
                $raw_hmac = hash_hmac(self::HMAC_ALGO, $raw_body, $decoded_secret, true);
                if ($this->constant_time_compare(bin2hex($raw_hmac), $signature_header)) {
                    return true;
                }
            }
            $raw_hmac_str = hash_hmac(self::HMAC_ALGO, $raw_body, $secret, true);
            if ($this->constant_time_compare(base64_encode($raw_hmac_str), $signature_header)) {
                return true;
            }
            if ($this->constant_time_compare(bin2hex($raw_hmac_str), $signature_header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Single structured log line on the short-secret rejection path.
     * Same JSON shape as gateway.call lines so ops can grep one log
     * stream. The secret value is NEVER included — only its length.
     */
    private function log_short_secret(string $slot, int $length): void {
        error_log(json_encode([
            'event'  => 'webhook.secret_too_short',
            'slot'   => $slot,
            'length' => $length,
            'min'    => self::MIN_SECRET_BYTES,
        ]));
    }

    /**
     * Constant-time signature comparison. Wrapping hash_equals here makes
     * the static-analysis grep for forbidden comparisons (rule S3) trivial.
     */
    private function constant_time_compare(string $expected, string $provided): bool {
        return hash_equals($expected, $provided);
    }
}
