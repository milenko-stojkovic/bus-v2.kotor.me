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
