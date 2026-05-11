<?php
namespace local_fastpix\util;

defined('MOODLE_INTERNAL') || die();

/**
 * Single source of truth for the asset-cache key formula.
 *
 * The MUC area 'local_fastpix/asset' is declared simplekeys=true, which
 * restricts keys to alphanumeric + underscore. The plugin caches each
 * asset row under TWO keys (the fastpix_id lookup and the playback_id
 * lookup), so we hash both ID strings and add a 2-char prefix to keep
 * the namespaces disjoint.
 *
 * Hash: SHA-256 truncated to 32 hex chars (128 bits of effective output).
 * The truncation rationale and an empirical collision test live in
 * tests/cache_keys_collision_test.php. CRC32's 32-bit width gave a
 * ~77K-asset birthday-collision threshold which led to cross-asset
 * metadata leak — replaced per REVIEW §S-1.
 *
 * Consumed by:
 *   - \local_fastpix\service\asset_service       (read + invalidate)
 *   - \local_fastpix\webhook\projector           (invalidate inside lock)
 *   - \local_fastpix\task\asset_cleanup          (invalidate after delete)
 *   - \local_fastpix\task\purge_soft_deleted_assets (invalidate on purge)
 *
 * Any future caller that needs an asset-cache key MUST use these methods.
 */
class cache_keys {

    /** Number of hex chars retained from the SHA-256 digest. */
    private const TRUNCATE_TO = 32;

    public static function fastpix(string $fastpix_id): string {
        return 'fp_' . substr(hash('sha256', $fastpix_id), 0, self::TRUNCATE_TO);
    }

    public static function playback(string $playback_id): string {
        return 'pb_' . substr(hash('sha256', $playback_id), 0, self::TRUNCATE_TO);
    }
}
