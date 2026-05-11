<?php
namespace local_fastpix\service;

defined('MOODLE_INTERNAL') || die();

class playback_service_test extends \advanced_testcase {

    private const TABLE = 'local_fastpix_asset';

    public function setUp(): void {
        $this->resetAfterTest();
        \cache::make('local_fastpix', 'asset')->purge();
    }

    private function bootstrap_signing_key(): void {
        // The fastest path: generate an RSA keypair in-process, store via
        // config so jwt_signing_service can mint without a gateway call.
        $res = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($res, $pem);
        set_config('signing_key_id', 'kid-test', 'local_fastpix');
        set_config('signing_private_key', base64_encode($pem), 'local_fastpix');
    }

    private function insert_asset(array $overrides = []): \stdClass {
        global $DB;
        $now = time();
        $row = (object)array_merge([
            'fastpix_id'             => 'media-' . random_string(8),
            'playback_id'            => 'pb-' . random_string(8),
            'owner_userid'           => 0,
            'title'                  => 'Test',
            'duration'               => 10.0,
            'status'                 => 'ready',
            'access_policy'          => 'public',
            'drm_required'           => 0,
            'no_skip_required'       => 0,
            'has_captions'           => 0,
            'last_event_id'          => null,
            'last_event_at'          => null,
            'deleted_at'             => null,
            'gdpr_delete_pending_at' => null,
            'gdpr_delete_attempts'   => 0,
            'timecreated'            => $now,
            'timemodified'           => $now,
        ], $overrides);
        $row->id = $DB->insert_record(self::TABLE, $row);
        return $row;
    }

    public function test_resolve_returns_payload_for_ready_asset(): void {
        $this->bootstrap_signing_key();
        $asset = $this->insert_asset();

        $payload = playback_service::resolve($asset->fastpix_id, 42);

        $this->assertSame($asset->playback_id, $payload->playback_id);
        $this->assertNotEmpty($payload->playback_token);
        $this->assertGreaterThan(time(), $payload->expires_at_ts);
        $this->assertFalse($payload->drm_required);
    }

    public function test_resolve_throws_asset_not_found_for_missing(): void {
        $this->expectException(\local_fastpix\exception\asset_not_found::class);
        playback_service::resolve('media-nonexistent', 42);
    }

    public function test_resolve_throws_asset_not_found_for_soft_deleted(): void {
        $this->bootstrap_signing_key();
        $asset = $this->insert_asset(['deleted_at' => time() - 60]);
        $this->expectException(\local_fastpix\exception\asset_not_found::class);
        playback_service::resolve($asset->fastpix_id, 42);
    }

    public function test_resolve_throws_asset_not_ready_when_status_not_ready(): void {
        $this->bootstrap_signing_key();
        $asset = $this->insert_asset(['status' => 'created']);
        $this->expectException(\local_fastpix\exception\asset_not_ready::class);
        playback_service::resolve($asset->fastpix_id, 42);
    }

    public function test_resolve_sets_drm_required_when_asset_is_drm(): void {
        $this->bootstrap_signing_key();
        $asset = $this->insert_asset(['drm_required' => 1, 'access_policy' => 'drm']);
        $payload = playback_service::resolve($asset->fastpix_id, 42);
        $this->assertTrue($payload->drm_required);
    }
}
