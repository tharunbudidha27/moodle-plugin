<?php
namespace local_fastpix\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Rotates the local RS256 signing key every 90 days. Keeps the previous key
 * around so JWTs already issued under it remain verifiable until natural TTL
 * expiry. NEVER logs the PEM (rule S1).
 */
class signing_key_rotator extends \core\task\scheduled_task {

    private const ROTATE_AFTER_SECONDS = 7776000; // 90 days

    public function get_name(): string {
        return get_string('task_signing_key_rotator', 'local_fastpix');
    }

    public function execute(): void {
        $created_at = (int)get_config('local_fastpix', 'signing_key_created_at');

        if ($created_at <= 0) {
            // First time we're tracking creation; record now and exit.
            set_config('signing_key_created_at', time(), 'local_fastpix');
            mtrace('signing_key_rotator: no creation timestamp; seeded with now');
            return;
        }

        if ((time() - $created_at) < self::ROTATE_AFTER_SECONDS) {
            return; // Not yet 90 days old.
        }

        $old_kid = (string)get_config('local_fastpix', 'signing_key_id');
        $old_pem = (string)get_config('local_fastpix', 'signing_private_key');

        try {
            // 1. Move the current key into the "previous" slot.
            set_config('signing_key_id_previous',      $old_kid, 'local_fastpix');
            set_config('signing_private_key_previous', $old_pem, 'local_fastpix');
            set_config('signing_key_rotated_at',       time(),   'local_fastpix');

            // 2. Mint a new key on the FastPix side.
            $response = \local_fastpix\api\gateway::instance()->create_signing_key();
            $new_kid = (string)($response->id ?? '');
            $new_pem = (string)($response->privateKey ?? '');

            if ($new_kid === '' || $new_pem === '') {
                throw new \RuntimeException('gateway returned empty signing key payload');
            }

            // 3. Store the new key (PEM base64-encoded for safe single-line storage).
            set_config('signing_key_id',         $new_kid,                'local_fastpix');
            set_config('signing_private_key',   base64_encode($new_pem),  'local_fastpix');
            set_config('signing_key_created_at', time(),                  'local_fastpix');

            // Log only the kid — never the PEM (S1).
            mtrace("signing_key_rotator: rotated to new kid={$new_kid}");

        } catch (\Throwable $e) {
            // Roll back the "previous" slot to whatever it was so we don't
            // leave a half-rotated state on the next run.
            set_config('signing_key_id_previous',      '', 'local_fastpix');
            set_config('signing_private_key_previous', '', 'local_fastpix');
            set_config('signing_key_rotated_at',       0,  'local_fastpix');

            mtrace('signing_key_rotator: rotation failed; existing key retained: '
                . $e->getMessage());
        }
    }
}
