<?php
namespace local_fastpix\webhook;

defined('MOODLE_INTERNAL') || die();

// The processor delegates to verifier::verify() which accepts the legacy
// raw-string secret + hex output format only when LOCAL_FASTPIX_DEBUG_VERIFIER
// is defined. Tests opt in.
defined('LOCAL_FASTPIX_DEBUG_VERIFIER') || define('LOCAL_FASTPIX_DEBUG_VERIFIER', true);

/**
 * Unit tests for the extracted webhook processor.
 */
class processor_test extends \advanced_testcase {

    private const TABLE = 'local_fastpix_webhook_event';
    private const SECRET =
        'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    public function setUp(): void {
        $this->resetAfterTest();
        verifier::reset();
        set_config('webhook_secret_current',     self::SECRET, 'local_fastpix');
        set_config('webhook_secret_previous',    '',           'local_fastpix');
        set_config('webhook_secret_rotated_at',  0,            'local_fastpix');
    }

    public function tearDown(): void {
        verifier::reset();
    }

    private function build_event_payload(?string $event_id = null): string {
        return json_encode([
            'id'         => $event_id ?? ('evt-' . random_string(8)),
            'type'       => 'video.media.created',
            'occurredAt' => time(),
            'object'     => ['type' => 'video.media', 'id' => 'media-' . random_string(6)],
            'data'       => (object)['title' => 'fixture'],
        ], JSON_UNESCAPED_SLASHES);
    }

    private function sign(string $payload, string $secret = self::SECRET): string {
        // Use the legacy raw-string + hex output format that verifier accepts
        // when LOCAL_FASTPIX_DEBUG_VERIFIER is defined (test mode).
        return hash_hmac('sha256', $payload, $secret);
    }

    public function test_process_accepts_valid_signed_event(): void {
        global $DB;
        $payload = $this->build_event_payload('evt-happy-1');
        $result = processor::process($payload, $this->sign($payload));

        $this->assertSame(processor::RESULT_ACCEPTED, $result['result']);
        $this->assertNull($result['error']);
        $this->assertIsInt($result['ledger_id']);
        $this->assertTrue($DB->record_exists(self::TABLE, [
            'provider_event_id' => 'evt-happy-1',
            'status'            => 'pending',
        ]));
    }

    public function test_process_rejects_bad_signature(): void {
        global $DB;
        $payload = $this->build_event_payload('evt-bad-sig');
        $bad = $this->sign($payload, str_repeat('z', 64));

        $result = processor::process($payload, $bad);

        $this->assertSame(processor::RESULT_BAD_SIGNATURE, $result['result']);
        $this->assertNull($result['ledger_id']);
        $this->assertFalse($DB->record_exists(self::TABLE,
            ['provider_event_id' => 'evt-bad-sig']));
    }

    public function test_process_rejects_empty_signature(): void {
        $payload = $this->build_event_payload();
        $result = processor::process($payload, '');
        $this->assertSame(processor::RESULT_BAD_SIGNATURE, $result['result']);
        $this->assertDebuggingCalled('webhook signature verify: empty body or signature');
    }

    public function test_process_rejects_non_json_body(): void {
        $payload = 'not json at all';
        $result = processor::process($payload, $this->sign($payload));
        $this->assertSame(processor::RESULT_MALFORMED_BODY, $result['result']);
    }

    public function test_process_rejects_event_missing_id(): void {
        $payload = json_encode([
            'type'   => 'video.media.created',
            'object' => ['type' => 'video.media', 'id' => 'media-x'],
        ]);
        $result = processor::process($payload, $this->sign($payload));
        $this->assertSame(processor::RESULT_MALFORMED_BODY, $result['result']);
    }

    public function test_process_rejects_event_missing_type(): void {
        $payload = json_encode([
            'id'     => 'evt-no-type',
            'object' => ['type' => 'video.media', 'id' => 'media-x'],
        ]);
        $result = processor::process($payload, $this->sign($payload));
        $this->assertSame(processor::RESULT_MALFORMED_BODY, $result['result']);
    }

    public function test_process_returns_duplicate_on_resubmitted_event_id(): void {
        $payload = $this->build_event_payload('evt-dup-1');
        $sig = $this->sign($payload);

        $first = processor::process($payload, $sig);
        $this->assertSame(processor::RESULT_ACCEPTED, $first['result']);

        $second = processor::process($payload, $sig);
        $this->assertSame(processor::RESULT_DUPLICATE, $second['result']);
        $this->assertSame($first['ledger_id'], $second['ledger_id']);
    }

    /**
     * Rule W1: 200 unique event_ids each submitted twice in random order
     * yields exactly 200 ledger rows.
     */
    public function test_flood_with_50pct_duplicates_yields_unique_count(): void {
        global $DB;
        $unique = 200;
        $ids = [];
        for ($i = 0; $i < $unique; $i++) {
            $ids[] = 'evt-flood-' . $i;
        }
        $duplicated = array_merge($ids, $ids);
        shuffle($duplicated);

        $accepted = 0;
        $duplicates = 0;
        foreach ($duplicated as $eid) {
            $payload = $this->build_event_payload($eid);
            $result = processor::process($payload, $this->sign($payload));
            if ($result['result'] === processor::RESULT_ACCEPTED) {
                $accepted++;
            } elseif ($result['result'] === processor::RESULT_DUPLICATE) {
                $duplicates++;
            }
        }

        $this->assertSame($unique, $accepted);
        $this->assertSame($unique, $duplicates);
        $this->assertSame($unique, $DB->count_records(self::TABLE));
    }
}
