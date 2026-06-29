<?php

namespace App\Console\Commands;

use App\Models\TempData;
use Illuminate\Console\Command;

/**
 * Diagnose guest/agency checkout attempts from temp_data (payment init / Bankart session).
 */
class InspectCheckoutAttemptCommand extends Command
{
    protected $signature = 'payment:inspect-checkout-attempt
        {--email= : Filter by temp_data.email}
        {--date= : Filter reservation_date (Y-m-d)}
        {--plate= : Filter license_plate (normalized match)}
        {--merchant-transaction-id= : Exact merchant_transaction_id}';

    protected $description = 'List temp_data checkout attempts for diagnosis (email, date, plate, or MTID)';

    public function handle(): int
    {
        $email = $this->option('email');
        $date = $this->option('date');
        $plate = $this->option('plate');
        $mtid = $this->option('merchant-transaction-id');

        if (($email === null || $email === '')
            && ($date === null || $date === '')
            && ($plate === null || $plate === '')
            && ($mtid === null || $mtid === '')) {
            $this->error('Provide at least one filter: --email, --date, --plate, or --merchant-transaction-id');

            return self::FAILURE;
        }

        $query = TempData::query()->orderByDesc('id');

        if (is_string($email) && $email !== '') {
            $query->where('email', $email);
        }
        if (is_string($date) && $date !== '') {
            $query->whereDate('reservation_date', $date);
        }
        if (is_string($plate) && $plate !== '') {
            $normalized = strtoupper(preg_replace('/[^A-Z0-9]/', '', $plate) ?? $plate);
            $query->where('license_plate', $normalized);
        }
        if (is_string($mtid) && $mtid !== '') {
            $query->where('merchant_transaction_id', $mtid);
        }

        $rows = $query->limit(50)->get();

        if ($rows->isEmpty()) {
            $this->warn('No temp_data rows matched.');

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'status', 'resolution_reason', 'email', 'plate', 'date', 'kind', 'merchant_transaction_id', 'created_at'],
            $rows->map(fn (TempData $t) => [
                $t->id,
                $t->status,
                $t->resolution_reason ?? '—',
                $t->email ?? '—',
                $t->license_plate ?? '—',
                $t->reservation_date?->format('Y-m-d') ?? '—',
                $t->reservation_kind ?? '—',
                $t->merchant_transaction_id ?? '—',
                $t->created_at?->toDateTimeString() ?? '—',
            ]),
        );

        $this->line('');
        $this->line('Logs: search payments channel for checkout_* / bankart_create_session_* / payment_init_failed with merchant_transaction_id.');

        return self::SUCCESS;
    }
}
