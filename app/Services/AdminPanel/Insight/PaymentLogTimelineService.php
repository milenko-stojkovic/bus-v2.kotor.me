<?php

namespace App\Services\AdminPanel\Insight;

use Carbon\Carbon;

final class PaymentLogTimelineService
{
    /**
     * Very conservative parser:
     * - only lines that explicitly contain the MTID substring are included
     * - primary source: payments-YYYY-MM-DD.log
     *
     * @return array{available:bool,events:list<array{ts:string,label:string,raw:string}>,note:string}
     */
    public function timelineForMtid(string $merchantTransactionId): array
    {
        $mtid = trim($merchantTransactionId);
        if ($mtid === '') {
            return ['available' => false, 'events' => [], 'note' => 'Detaljni payment logovi nisu dostupni u retention periodu.'];
        }

        $logDir = storage_path('logs');
        $events = [];

        $days = $this->retentionDays();

        // Try last N days (best effort). If logs are rotated differently, this simply yields none.
        for ($i = 0; $i < $days; $i++) {
            $d = Carbon::now()->subDays($i)->format('Y-m-d');
            $path = $logDir.DIRECTORY_SEPARATOR.'payments-'.$d.'.log';
            if (! is_file($path)) {
                continue;
            }

            foreach ($this->readLinesContaining($path, $mtid) as $line) {
                $events[] = $this->classifyLine($line);
            }
        }

        if (count($events) === 0) {
            return [
                'available' => false,
                'events' => [],
                'note' => 'Detaljni payment logovi nisu dostupni u retention periodu.',
            ];
        }

        return [
            'available' => true,
            'events' => $events,
            'note' => '',
        ];
    }

    private function retentionDays(): int
    {
        // Source of truth: config/logging.php (payments is daily channel, days = LOG_DAILY_DAYS by default).
        $days = (int) config('logging.channels.payments.days', env('LOG_DAILY_DAYS', 14));
        if ($days < 1) {
            $days = 14;
        }

        return $days;
    }

    /**
     * @return iterable<string>
     */
    private function readLinesContaining(string $path, string $needle): iterable
    {
        $fh = @fopen($path, 'rb');
        if (! is_resource($fh)) {
            return;
        }

        try {
            while (! feof($fh)) {
                $line = fgets($fh);
                if (! is_string($line)) {
                    break;
                }
                if (strpos($line, $needle) !== false) {
                    yield trim($line);
                }
            }
        } finally {
            fclose($fh);
        }
    }

    /**
     * @return array{ts:string,label:string,raw:string}
     */
    private function classifyLine(string $line): array
    {
        $ts = $this->extractTimestamp($line) ?? '';
        $label = $this->extractLabel($line);

        return [
            'ts' => $ts,
            'label' => $label,
            'raw' => $line,
        ];
    }

    private function extractTimestamp(string $line): ?string
    {
        // Typical Laravel daily log: [2026-04-21 12:34:56] ...
        if (preg_match('/^\[(\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2})\]/', $line, $m)) {
            return $m[1];
        }
        // JSON logs might start with 2026-04-21T12:34:56...
        if (preg_match('/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2})/', $line, $m)) {
            return $m[1];
        }

        return null;
    }

    private function extractLabel(string $line): string
    {
        $l = mb_strtolower($line);
        if (str_contains($l, 'advance_topup')) {
            return 'advance topup';
        }
        if (str_contains($l, 'createsession')) {
            return 'createSession';
        }
        if (str_contains($l, 'callback')) {
            return 'callback';
        }
        if (str_contains($l, 'inquiry')) {
            return 'inquiry';
        }
        if (str_contains($l, 'state transition')) {
            return 'state transition';
        }
        if (str_contains($l, 'fiscal')) {
            return 'fiscal';
        }

        return 'payment';
    }
}

