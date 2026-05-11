<?php
namespace local_fastpix\service;

use local_fastpix\dto\playback_payload;
use local_fastpix\exception\asset_not_found;
use local_fastpix\exception\asset_not_ready;

defined('MOODLE_INTERNAL') || die();

/**
 * Public chokepoint for playback-token minting.
 *
 * Authorized by ADR-013. The four sibling plugins (mod_fastpix,
 * filter_fastpix, tinymce_fastpix, future viewer) MUST go through
 * this service to obtain a JWT — direct use of jwt_signing_service
 * is a PR-3 violation (consumer contract CC1).
 *
 * Read-path. No gateway call (rule W7), no DB write. The asset is
 * looked up via asset_service::get_by_fastpix_id which is MUC-cached;
 * the JWT mint itself is 1–5ms and never cached (rule S10).
 */
class playback_service {

    /**
     * Resolve a playback payload for a known, ready, non-deleted asset.
     *
     * The caller is trusted (mod_fastpix has already done require_login,
     * capability check, and activity-context verification). $userid is
     * accepted to match the consumer-contract signature and reserved
     * for future per-user policy hooks (rate limit, watermark
     * personalisation); unused today.
     *
     * @throws asset_not_found  if asset missing or soft-deleted
     * @throws asset_not_ready  if asset exists but status != 'ready'
     * @throws \local_fastpix\exception\signing_key_missing  bubbled from JWT mint
     */
    public static function resolve(string $fastpix_id, int $userid): playback_payload {
        $asset = asset_service::get_by_fastpix_id($fastpix_id);
        if ($asset === null) {
            throw new asset_not_found($fastpix_id);
        }
        if ($asset->status !== 'ready') {
            throw new asset_not_ready($fastpix_id . ' (status=' . $asset->status . ')');
        }

        $signer = new jwt_signing_service();
        $jwt = $signer->sign_for_playback((string)$asset->playback_id);
        $ttl = $signer->token_ttl_seconds();

        return playback_payload::from_asset_and_jwt(
            $asset,
            $jwt,
            $ttl,
            null,
            false,
        );
    }
}
