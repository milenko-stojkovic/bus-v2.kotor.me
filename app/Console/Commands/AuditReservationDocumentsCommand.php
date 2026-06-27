<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use App\Services\Reservation\ReservationEmailSendClaimService;
use App\Support\ReservationPdfFilename;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Diagnostic: list paid/free reservations for a date and flag likely missing document emails.
 */
class AuditReservationDocumentsCommand extends Command
{
    protected $signature = 'mail:audit-reservation-documents
                            {--date= : Reservation date (Y-m-d); default today Europe/Podgorica}
                            {--missing-only : Show only rows that look like missing email}';

    protected $description = 'Audit customer reservation document emails (invoice/confirmation) for a date';

    public function handle(ReservationEmailSendClaimService $claimService): int
    {
        $date = $this->option('date')
            ? Carbon::parse((string) $this->option('date'), 'Europe/Podgorica')->toDateString()
            : now('Europe/Podgorica')->toDateString();

        $missingOnly = (bool) $this->option('missing-only');

        $rows = Reservation::query()
            ->whereDate('reservation_date', $date)
            ->whereIn('status', ['paid', 'free'])
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            $this->warn("No paid/free reservations for reservation_date={$date}.");

            return self::SUCCESS;
        }

        $headers = [
            'reservation_id',
            'merchant_transaction_id',
            'email',
            'status',
            'reservation_kind',
            'invoice_sent_at',
            'email_sent',
            'expected_pdf',
            'looks_missing',
            'notes',
        ];

        $tableRows = [];
        $missingCount = 0;

        foreach ($rows as $reservation) {
            $assessment = $this->assess($reservation, $claimService);
            if ($assessment['looks_missing']) {
                $missingCount++;
            }

            if ($missingOnly && ! $assessment['looks_missing']) {
                continue;
            }

            $tableRows[] = [
                $reservation->id,
                $reservation->merchant_transaction_id ?? '—',
                $reservation->email ?? '—',
                $reservation->status,
                $reservation->reservation_kind ?? '—',
                $reservation->invoice_sent_at?->format('Y-m-d H:i:s') ?? 'null',
                (string) $reservation->email_sent,
                $assessment['expected_pdf'],
                $assessment['looks_missing'] ? 'YES' : 'no',
                $assessment['notes'],
            ];
        }

        $this->info("Reservation document email audit for reservation_date={$date} ({$rows->count()} total, {$missingCount} likely missing)");
        $this->table($headers, $tableRows);

        return self::SUCCESS;
    }

    /**
     * @return array{expected_pdf: string, looks_missing: bool, notes: string}
     */
    private function assess(Reservation $reservation, ReservationEmailSendClaimService $claimService): array
    {
        $expectedPdf = ReservationPdfFilename::forReservation($reservation);
        $notes = [];

        if ($reservation->email === null || trim((string) $reservation->email) === '') {
            $notes[] = 'no_recipient_email';
        }

        $looksMissing = false;

        if ((int) $reservation->email_sent === Reservation::EMAIL_NOT_SENT) {
            $looksMissing = true;
            $notes[] = 'email_not_sent';
        } elseif ((int) $reservation->email_sent === Reservation::EMAIL_SENDING) {
            if ($claimService->isStaleSendingLock($reservation)) {
                $looksMissing = true;
                $notes[] = 'stuck_email_sending';
            } else {
                $notes[] = 'email_sending_in_progress';
            }
        } elseif ($reservation->invoice_sent_at === null) {
            $looksMissing = true;
            $notes[] = 'invoice_sent_at_null';
        }

        if ($this->hasRecentFailedJob($reservation->id)) {
            $notes[] = 'failed_jobs_entry';
            if (! $looksMissing && $reservation->invoice_sent_at === null) {
                $looksMissing = true;
            }
        }

        if ($reservation->invoice_sent_at !== null && (int) $reservation->email_sent === Reservation::EMAIL_SENT) {
            $notes[] = 'db_marked_sent';
        }

        return [
            'expected_pdf' => $expectedPdf,
            'looks_missing' => $looksMissing,
            'notes' => $notes === [] ? '—' : implode(', ', $notes),
        ];
    }

    private function hasRecentFailedJob(int $reservationId): bool
    {
        if (! DB::getSchemaBuilder()->hasTable('failed_jobs')) {
            return false;
        }

        return DB::table('failed_jobs')
            ->where(function ($q) use ($reservationId): void {
                $q->where('payload', 'like', '%"reservationId":'.$reservationId.'%')
                    ->orWhere('payload', 'like', '%"reservationId";i:'.$reservationId.'%');
            })
            ->exists();
    }
}
