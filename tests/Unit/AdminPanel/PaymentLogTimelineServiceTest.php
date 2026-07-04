<?php

namespace Tests\Unit\AdminPanel;

use App\Services\AdminPanel\Insight\PaymentLogTimelineService;
use Carbon\Carbon;
use Tests\TestCase;

class PaymentLogTimelineServiceTest extends TestCase
{
    private PaymentLogTimelineService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new PaymentLogTimelineService;
        config()->set('logging.channels.payments.days', 14);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_events_from_same_day_sort_ascending(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');

        $mtid = 'mt-timeline-same-day';
        $this->writePaymentLog('2026-06-30', implode("\n", [
            '[2026-06-30 05:32:00] local.INFO: inquiry {"merchant_transaction_id":"'.$mtid.'"}',
            '[2026-06-30 00:01:00] local.INFO: createSession {"merchant_transaction_id":"'.$mtid.'"}',
            '[2026-06-30 02:15:00] local.INFO: callback {"merchant_transaction_id":"'.$mtid.'"}',
        ])."\n");

        $result = $this->service->timelineForMtid($mtid);

        $this->assertTrue($result['available']);
        $this->assertSame(
            ['2026-06-30 00:01:00', '2026-06-30 02:15:00', '2026-06-30 05:32:00'],
            array_column($result['events'], 'ts'),
        );
    }

    public function test_events_from_different_log_files_sort_ascending_across_days(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');

        $mtid = 'mt-timeline-cross-day';
        $this->writePaymentLog('2026-06-30', '[2026-06-30 05:32:00] local.INFO: inquiry {"merchant_transaction_id":"'.$mtid.'"}'."\n");
        $this->writePaymentLog('2026-06-29', '[2026-06-29 22:35:00] local.INFO: createSession {"merchant_transaction_id":"'.$mtid.'"}'."\n");
        $this->writePaymentLog('2026-06-30', '[2026-06-30 00:01:00] local.INFO: callback {"merchant_transaction_id":"'.$mtid.'"}'."\n", append: true);

        $result = $this->service->timelineForMtid($mtid);

        $this->assertTrue($result['available']);
        $this->assertSame(
            ['2026-06-29 22:35:00', '2026-06-30 00:01:00', '2026-06-30 05:32:00'],
            array_column($result['events'], 'ts'),
        );
    }

    public function test_late_evening_event_before_midnight_next_day(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');

        $mtid = 'mt-timeline-midnight';
        $this->writePaymentLog('2026-06-30', '[2026-06-30 00:01:00] local.INFO: callback {"merchant_transaction_id":"'.$mtid.'"}'."\n");
        $this->writePaymentLog('2026-06-29', '[2026-06-29 22:35:00] local.INFO: createSession {"merchant_transaction_id":"'.$mtid.'"}'."\n");

        $result = $this->service->timelineForMtid($mtid);

        $this->assertSame(
            ['2026-06-29 22:35:00', '2026-06-30 00:01:00'],
            array_column($result['events'], 'ts'),
        );
    }

    public function test_same_timestamp_keeps_deterministic_source_order(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');

        $mtid = 'mt-timeline-same-ts';
        $this->writePaymentLog('2026-06-30', implode("\n", [
            '[2026-06-30 10:00:00] local.INFO: createSession first {"merchant_transaction_id":"'.$mtid.'"}',
            '[2026-06-30 10:00:00] local.INFO: createSession second {"merchant_transaction_id":"'.$mtid.'"}',
        ])."\n");

        $result = $this->service->timelineForMtid($mtid);

        $this->assertStringContainsString('createSession first', $result['events'][0]['raw']);
        $this->assertStringContainsString('createSession second', $result['events'][1]['raw']);
    }

    public function test_unparseable_timestamp_is_last_and_marked_safely(): void
    {
        Carbon::setTestNow('2026-06-30 12:00:00');

        $mtid = 'mt-timeline-unparsed';
        $this->writePaymentLog('2026-06-30', implode("\n", [
            'unparseable line with '.$mtid.' and no timestamp',
            '[2026-06-30 08:00:00] local.INFO: callback {"merchant_transaction_id":"'.$mtid.'"}',
        ])."\n");

        $result = $this->service->timelineForMtid($mtid);

        $this->assertTrue($result['available']);
        $this->assertCount(2, $result['events']);
        $this->assertSame('2026-06-30 08:00:00', $result['events'][0]['ts']);
        $this->assertTrue($result['events'][1]['timestamp_unparsed'] ?? false);
        $this->assertSame('', $result['events'][1]['ts']);
        $this->assertStringContainsString($mtid, $result['events'][1]['raw']);
    }

    public function test_multiline_fiscal_log_entry_is_merged_and_not_duplicated_at_end(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');

        $mtid = '22d818bf-3a97-4d39-a104-f43c16050b72';
        $this->writePaymentLog('2026-07-04', implode("\n", [
            '[2026-07-04 07:12:31] production.INFO: Real fiscalization request start {"merchant_transaction_id":"'.$mtid.'"}',
            '[2026-07-04 07:12:32] production.WARNING: Real fiscal deposit failed response {"reservation_id":23700,"merchant_transaction_id":"'.$mtid.'","status":200,"error_code":"999","error":"',
            'Detalji greške: The underlying connection was closed: The connection was closed unexpectedly. ","body":"{\"UIDRequest\":\"'.$mtid.'\",\"IsSucccess\":false,\"Error\":{\"ErrorCode\":\"999\"}}"}',
            '[2026-07-04 07:30:05] production.INFO: Real fiscalization request success {"merchant_transaction_id":"'.$mtid.'"}',
        ])."\n");

        $result = $this->service->timelineForMtid($mtid);

        $this->assertTrue($result['available']);
        $this->assertCount(3, $result['events']);
        $this->assertSame(
            ['2026-07-04 07:12:31', '2026-07-04 07:12:32', '2026-07-04 07:30:05'],
            array_column($result['events'], 'ts'),
        );
        $this->assertSame('fiscal', $result['events'][1]['label']);
        $this->assertStringContainsString('Real fiscal deposit failed response', $result['events'][1]['raw']);
        $this->assertStringContainsString('connection was closed unexpectedly', $result['events'][1]['raw']);
        $this->assertStringContainsString($mtid, $result['events'][1]['raw']);
        $this->assertFalse($result['events'][1]['timestamp_unparsed'] ?? false);
    }

    public function test_multiline_continuation_without_mtid_on_fragment_is_not_separate_event(): void
    {
        Carbon::setTestNow('2026-07-04 12:00:00');

        $mtid = 'mt-multiline-no-orphan';
        $this->writePaymentLog('2026-07-04', implode("\n", [
            '[2026-07-04 07:12:32] production.WARNING: Real fiscal deposit failed response {"merchant_transaction_id":"'.$mtid.'","error":"',
            'Detalji greške: connection closed unexpectedly. ","body":"{\"ErrorCode\":\"999\"}"}',
            '[2026-07-04 07:30:05] production.INFO: Real fiscalization request success {"merchant_transaction_id":"'.$mtid.'"}',
        ])."\n");

        $result = $this->service->timelineForMtid($mtid);

        $this->assertCount(2, $result['events']);
        foreach ($result['events'] as $event) {
            $this->assertFalse($event['timestamp_unparsed'] ?? false);
            $this->assertNotSame('', $event['ts']);
        }
    }

    private function writePaymentLog(string $date, string $content, bool $append = false): void
    {
        $logDir = storage_path('logs');
        @mkdir($logDir, 0777, true);
        $path = $logDir.DIRECTORY_SEPARATOR.'payments-'.$date.'.log';

        if ($append) {
            file_put_contents($path, $content, FILE_APPEND);

            return;
        }

        file_put_contents($path, $content);
    }
}
