<?php
namespace local_fastpix\dto;

defined('MOODLE_INTERNAL') || die();

class playback_payload {

    public function __construct(
        public readonly string $playback_id,
        public readonly string $jwt,
        public readonly int $expires_at,
        public readonly bool $drm_required,
        public readonly bool $no_skip_required,
        public readonly ?string $watermark_html,
        public readonly bool $tracking_enabled,
    ) {}

    public static function from_asset_and_jwt(
        \stdClass $asset,
        string $jwt,
        int $ttl_seconds,
        ?string $watermark_html,
        bool $tracking_enabled,
    ): self {
        return new self(
            playback_id:       (string)$asset->playback_id,
            jwt:               $jwt,
            expires_at:        time() + $ttl_seconds,
            drm_required:      (bool)($asset->drm_required ?? false),
            no_skip_required:  (bool)($asset->no_skip_required ?? false),
            watermark_html:    $watermark_html,
            tracking_enabled:  $tracking_enabled,
        );
    }
}
