<?php

namespace App\Services\AdminPanel\Insight;

use Carbon\Carbon;

final class PaymentLogTimelineService
{
    /**
     * Very conservative parser:
     * - primary source: payments-YYYY-MM-DD.log
     * - multiline log records are merged before filtering (Laravel context JSON may span lines)
     * - only complete records that contain the MTID substring are included
     *
     * @return array{available:bool,events:list<array{ts:string,label:string,raw:string,timestamp_unparsed?:bool}>,note:string}
     */
    public function timelineForMtid(string $merchantTransactionId): array
    {
        $mtid = trim($merchantTransactionId);
        if ($mtid === '') {
            return ['available' => false, 'events' => [], 'note' => 'Detaljni payment logovi nisu dostupni u retention periodu.'];
        }

        $logDir = storage_path('logs');
        $events = [];
        $sourceIndex = 0;

        $days = $this->retentionDays();

        // Try last N days (best effort). If logs are rotated differently, this simply yields none.
        for ($i = 0; $i < $days; $i++) {
            $d = Carbon::now()->subDays($i)->format('Y-m-d');
            $path = $logDir.DIRECTORY_SEPARATOR.'payments-'.$d.'.log';
            if (! is_file($path)) {
                continue;
            }

            foreach ($this->readLogEntriesContaining($path, $mtid) as $entry) {
                $events[] = $this->classifyLine($entry, $sourceIndex);
                $sourceIndex++;
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
            'events' => $this->sortEventsChronologically($events),
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
     * Read physical lines and merge continuation fragments into one logical log record.
     *
     * @return iterable<string>
     */
    private function readLogEntriesContaining(string $path, string $needle): iterable
    {
        $fh = @fopen($path, 'rb');
        if (! is_resource($fh)) {
            return;
        }

        try {
            $buffer = '';

            while (! feof($fh)) {
                $line = fgets($fh);
                if (! is_string($line)) {
                    break;
                }

                if ($buffer !== '' && $this->isLogEntryStart($line)) {
                    $merged = trim($buffer);
                    if ($merged !== '' && str_contains($merged, $needle)) {
                        yield $merged;
                    }
                    $buffer = $line;
                } elseif ($buffer === '') {
                    $buffer = $line;
                } else {
                    $buffer .= $line;
                }
            }

            if ($buffer !== '') {
                $merged = trim($buffer);
                if ($merged !== '' && str_contains($merged, $needle)) {
                    yield $merged;
                }
            }
        } finally {
            fclose($fh);
        }
    }

    private function isLogEntryStart(string $line): bool
    {
        return preg_match('/^\[\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}\]/', $line) === 1
            || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}/', $line) === 1;
    }

    /**
     * @param  list<array{ts:string,label:string,raw:string,timestamp_unparsed?:bool,_source_index:int}>  $events
     * @return list<array{ts:string,label:string,raw:string,timestamp_unparsed?:bool}>
     */
    private function sortEventsChronologically(array $events): array
    {
        usort($events, function (array $a, array $b): int {
            $aUnparsed = (bool) ($a['timestamp_unparsed'] ?? false);
            $bUnparsed = (bool) ($b['timestamp_unparsed'] ?? false);

            if ($aUnparsed !== $bUnparsed) {
                return $aUnparsed <=> $bUnparsed;
            }

            if (! $aUnparsed) {
                $tsCmp = $this->timestampSortKey((string) ($a['ts'] ?? '')) <=> $this->timestampSortKey((string) ($b['ts'] ?? ''));
                if ($tsCmp !== 0) {
                    return $tsCmp;
                }
            }

            return (int) ($a['_source_index'] ?? 0) <=> (int) ($b['_source_index'] ?? 0);
        });

        return array_map(static function (array $event): array {
            unset($event['_source_index']);

            return $event;
        }, $events);
    }

    private function timestampSortKey(string $ts): int
    {
        if ($ts === '') {
            return PHP_INT_MAX;
        }

        try {
            return Carbon::parse(str_replace('T', ' ', $ts))->getTimestamp();
        } catch (\Throwable) {
            return PHP_INT_MAX;
        }
    }

    /**
     * @return array{ts:string,label:string,raw:string,timestamp_unparsed?:bool,_source_index:int}
     */
    private function classifyLine(string $line, int $sourceIndex): array
    {
        $parsedTs = $this->extractTimestamp($line);
        $label = $this->extractLabel($line);

        $event = [
            'ts' => $parsedTs ?? '',
            'label' => $label,
            'raw' => $line,
            '_source_index' => $sourceIndex,
        ];

        if ($parsedTs === null) {
            $event['timestamp_unparsed'] = true;
        }

        return $event;
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

