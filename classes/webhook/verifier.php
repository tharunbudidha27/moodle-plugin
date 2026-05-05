<?php
namespace local_fastpix\webhook;

defined('MOODLE_INTERNAL') || die();

class verifier {

    private const HMAC_ALGO        = 'sha256';
    private const ROTATION_WINDOW  = 1800; // 30 minutes

    // Minimum acceptable length (bytes) for the configured webhook secret.
    // db/install.php seeds 64 hex chars (= 32 bytes of CSPRNG output).
    // Per REVIEW-2026-05-04 §S-6: a misconfigured short secret (e.g. 8 chars)
    // would otherwise pass the empty-check and silently accept signatures
    // forged against a low-entropy key. Enforced at verify time so the
    // floor applies regardless of how the secret got into config.
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
            // Silent acceptance is forbidden — explicit reject.
            debugging('webhook signature verify: current secret not configured', DEBUG_DEVELOPER);
            return false;
        }
        if (strlen($current) < self::MIN_SECRET_BYTES) {
            $this->log_short_secret('current', strlen($current));
            return false;
        }

        $expected_current = hash_hmac(self::HMAC_ALGO, $raw_body, $current);
        if ($this->constant_time_compare($expected_current, $signature_header)) {
            return true;
        }

        $previous = (string)get_config('local_fastpix', 'webhook_secret_previous');
        $rotated_at = (int)get_config('local_fastpix', 'webhook_secret_rotated_at');
        if ($previous !== '' && ($rotated_at > 0) && (time() - $rotated_at) < self::ROTATION_WINDOW) {
            if (strlen($previous) < self::MIN_SECRET_BYTES) {
                $this->log_short_secret('previous', strlen($previous));
                return false;
            }
            $expected_prev = hash_hmac(self::HMAC_ALGO, $raw_body, $previous);
            if ($this->constant_time_compare($expected_prev, $signature_header)) {
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
     * Constant-time signature comparison. Wrapping hash_equals here makes the
     * static-analysis grep for forbidden comparisons (rule S3) trivial.
     */
    private function constant_time_compare(string $expected, string $provided): bool {
        return hash_equals($expected, $provided);
    }
}
