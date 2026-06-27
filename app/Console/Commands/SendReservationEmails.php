<?php

namespace App\Console\Commands;

use App\Jobs\SendFreeReservationConfirmationJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\Reservation;
use Illuminate\Console\Command;

/**
 * Cron fallback: queue document emails for reservations where email was never sent.
 * Does NOT mark email_sent without actually dispatching a send job.
 */
class SendReservationEmails extends Command
{
    protected $signature = 'reservations:send-emails';

    protected $description = 'Queue reservation document emails where email_sent is not sent';

    public function handle(): int
    {
        $rows = Reservation::query()
            ->where('email_sent', Reservation::EMAIL_NOT_SENT)
            ->whereNull('invoice_sent_at')
            ->whereIn('status', ['paid', 'free'])
            ->get();

        $queued = 0;

        foreach ($rows as $reservation) {
            if ($reservation->email === null || trim((string) $reservation->email) === '') {
                continue;
            }

            if ($reservation->status === 'free') {
                SendFreeReservationConfirmationJob::dispatch($reservation->id);
            } else {
                SendInvoiceEmailJob::dispatch($reservation->id, $reservation->fiscal_jir !== null);
            }

            $queued++;
        }

        $this->info("Queued {$queued} reservation document email job(s) ({$rows->count()} candidate row(s)).");

        return self::SUCCESS;
    }
}
