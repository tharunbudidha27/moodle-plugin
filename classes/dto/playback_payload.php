<?php
namespace local_fastpix\dto;

defined('MOODLE_INTERNAL') || die();

/**
 * Public playback DTO returned from \local_fastpix\service\playback_service::resolve.
 *
 * Field names match ADR-013's documented consumer-contract surface exactly
 * (CC8). The four sibling plugins (mod_fastpix, filter_fastpix,
 * tinymce_fastpix, future viewer) consume this DTO directly — renaming a
 * field here is a major version bump and an ADR.
 */
class playback_payload {

    public function __construct(
        public readonly string $playback_id,
        public readonly string $playback_token,
        public readonly int $expires_at_ts,
        public readonly bool $drm_required,
        public readonly ?string $accent_color,
        public readonly bool $default_show_captions,
    ) {}

    /**
     * Construct from an asset row + freshly-minted JWT + activity-level
     * overrides. Activity-level fields (accent_color, default_show_captions)
     * come from the caller, NOT the asset — they're owned by the consuming
     * plugin's activity row, not by local_fastpix.
     *
     * @param \stdClass $asset       Row from local_fastpix_asset.
     * @param string    $jwt         Signed JWT from jwt_signing_service.
     * @param int       $ttl_seconds JWT TTL in seconds.
     * @param ?string   $accent_color Optional brand colour (CSS string) from the caller.
     * @param bool      $default_show_captions Whether captions are on by default for this activity.
     */
    public static function from_asset_and_jwt(
        \stdClass $asset,
        string $jwt,
        int $ttl_seconds,
        ?string $accent_color = null,
        bool $default_show_captions = false,
    ): self {
        return new self(
            playback_id:           (string)$asset->playback_id,
            playback_token:        $jwt,
            expires_at_ts:         time() + $ttl_seconds,
            drm_required:          (bool)($asset->drm_required ?? false),
            accent_color:          $accent_color,
            default_show_captions: $default_show_captions,
        );
    }
}
