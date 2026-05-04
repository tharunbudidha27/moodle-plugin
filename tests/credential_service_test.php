<?php
namespace local_fastpix\service;

defined('MOODLE_INTERNAL') || die();

class credential_service_test extends \advanced_testcase {

    private const FAKE_PEM =
        "-----BEGIN PRIVATE KEY-----\nFAKEPEMCONTENT\n-----END PRIVATE KEY-----";

    public function setUp(): void {
        $this->resetAfterTest();
        credential_service::reset();
    }

    public function tearDown(): void {
        credential_service::reset();
    }

    private function gateway_mock_returning_signing_key(): \local_fastpix\api\gateway {
        $mock = $this->createMock(\local_fastpix\api\gateway::class);
        $mock->method('create_signing_key')->willReturn((object)[
            'id'         => 'kid-test-1',
            'privateKey' => self::FAKE_PEM,
            'createdAt'  => '2026-05-04T00:00:00Z',
        ]);
        return $mock;
    }

    // --- apikey / apisecret ----------------------------------------------

    public function test_apikey_returns_configured_value(): void {
        set_config('apikey', 'sk-test-123', 'local_fastpix');
        $this->assertSame('sk-test-123', credential_service::instance()->apikey());
    }

    public function test_apikey_throws_credentials_missing_when_empty(): void {
        try {
            credential_service::instance()->apikey();
            $this->fail('expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('credentials_missing', $e->errorcode);
        }
    }

    public function test_apisecret_returns_configured_value(): void {
        set_config('apisecret', 'shh-very-secret', 'local_fastpix');
        $this->assertSame('shh-very-secret', credential_service::instance()->apisecret());
    }

    public function test_apisecret_throws_credentials_missing_when_empty(): void {
        try {
            credential_service::instance()->apisecret();
            $this->fail('expected moodle_exception');
        } catch (\moodle_exception $e) {
            $this->assertSame('credentials_missing', $e->errorcode);
        }
    }

    // --- ensure_signing_key ----------------------------------------------

    public function test_ensure_signing_key_is_idempotent_when_already_configured(): void {
        set_config('signing_key_id', 'pre-existing-kid', 'local_fastpix');
        set_config('signing_private_key', base64_encode(self::FAKE_PEM), 'local_fastpix');

        $mock = $this->createMock(\local_fastpix\api\gateway::class);
        $mock->expects($this->never())->method('create_signing_key');

        $service = credential_service::instance();
        $service->set_gateway($mock);
        $service->ensure_signing_key();

        // Config left untouched.
        $this->assertSame('pre-existing-kid', get_config('local_fastpix', 'signing_key_id'));
    }

    public function test_ensure_signing_key_calls_gateway_when_not_configured(): void {
        set_config('signing_key_id', '', 'local_fastpix');
        set_config('signing_private_key', '', 'local_fastpix');

        $service = credential_service::instance();
        $service->set_gateway($this->gateway_mock_returning_signing_key());
        $service->ensure_signing_key();

        $this->assertSame('kid-test-1', get_config('local_fastpix', 'signing_key_id'));
        $this->assertNotEmpty(get_config('local_fastpix', 'signing_private_key'));
    }

    public function test_ensure_signing_key_stores_pem_base64_encoded(): void {
        set_config('signing_key_id', '', 'local_fastpix');
        set_config('signing_private_key', '', 'local_fastpix');

        $service = credential_service::instance();
        $service->set_gateway($this->gateway_mock_returning_signing_key());
        $service->ensure_signing_key();

        $stored = (string)get_config('local_fastpix', 'signing_private_key');
        $decoded = base64_decode($stored, true);
        $this->assertSame(self::FAKE_PEM, $decoded);
    }

    // --- Redaction canary ------------------------------------------------

    public function test_redaction_canary_no_pem_in_logs(): void {
        set_config('signing_key_id', '', 'local_fastpix');
        set_config('signing_private_key', '', 'local_fastpix');

        $tmp = tempnam(sys_get_temp_dir(), 'credlog_');
        $original = ini_get('error_log');
        ini_set('error_log', $tmp);

        try {
            $service = credential_service::instance();
            $service->set_gateway($this->gateway_mock_returning_signing_key());
            $service->ensure_signing_key();
            $log_buffer = (string)file_get_contents($tmp);
        } finally {
            ini_set('error_log', $original);
            @unlink($tmp);
        }

        $this->assertStringNotContainsString('FAKEPEMCONTENT', $log_buffer);
        $this->assertStringNotContainsString('-----BEGIN', $log_buffer);
        $this->assertStringNotContainsString(self::FAKE_PEM, $log_buffer);

        // The kid is fine to log — it is not a secret.
        $this->assertStringContainsString('kid-test-1', $log_buffer);
    }
}
