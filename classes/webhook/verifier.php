<?php
namespace local_fastpix\webhook;

defined('MOODLE_INTERNAL') || die();

class verifier {

    private const HMAC_ALGO        = 'sha256';
    private const ROTATION_WINDOW  = 1800; // 30 minutes

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

        $expected_current = hash_hmac(self::HMAC_ALGO, $raw_body, $current);
        if ($this->constant_time_compare($expected_current, $signature_header)) {
            return true;
        }

        $previous = (string)get_config('local_fastpix', 'webhook_secret_previous');
        $rotated_at = (int)get_config('local_fastpix', 'webhook_secret_rotated_at');
        if ($previous !== '' && ($rotated_at > 0) && (time() - $rotated_at) < self::ROTATION_WINDOW) {
            $expected_prev = hash_hmac(self::HMAC_ALGO, $raw_body, $previous);
            if ($this->constant_time_compare($expected_prev, $signature_header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Constant-time signature comparison. Wrapping hash_equals here makes the
     * static-analysis grep for forbidden comparisons (rule S3) trivial.
     */
    private function constant_time_compare(string $expected, string $provided): bool {
        return hash_equals($expected, $provided);
    }
}
