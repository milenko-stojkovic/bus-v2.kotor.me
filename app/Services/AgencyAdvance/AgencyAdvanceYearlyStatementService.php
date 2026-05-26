<?php

namespace App\Services\AgencyAdvance;

use App\Mail\AgencyAdvanceYearlyStatementMail;
use App\Models\AgencyAdvanceTransaction;
use App\Models\AgencyAdvanceYearlyStatement;
use App\Models\User;
use App\Services\Pdf\AgencyAdvanceYearlyStatementPdfGenerator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

final class AgencyAdvanceYearlyStatementService
{
    public function __construct(
        private readonly AgencyAdvanceYearlyStatementPdfGenerator $pdf,
    ) {}

    /**
     * @return array{
     *   opening_balance: float,
     *   rows: list<array{date:string,type:string,description:string,amount:float,balance_after:float}>,
     *   totals: array{topup_total: float, usage_total: float, correction_total: float},
     *   closing_balance: float
     * }
     */
    public function buildForAgencyYear(User $agency, int $year): array
    {
        $start = Carbon::create($year, 1, 1, 0, 0, 0)->startOfDay();
        $end = Carbon::create($year, 12, 31, 23, 59, 59)->endOfDay();

        $opening = (float) AgencyAdvanceTransaction::query()
            ->where('agency_user_id', $agency->id)
            ->where('created_at', '<', $start->toDateTimeString())
            ->sum('amount');

        $txs = AgencyAdvanceTransaction::query()
            ->where('agency_user_id', $agency->id)
            ->where('created_at', '>=', $start->toDateTimeString())
            ->where('created_at', '<=', $end->toDateTimeString())
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $running = $opening;
        $rows = [];

        $topupTotal = 0.0;
        $usageTotal = 0.0;
        $correctionTotal = 0.0;

        foreach ($txs as $tx) {
            $amount = (float) $tx->amount;
            $running += $amount;

            if ($tx->type === AgencyAdvanceTransaction::TYPE_TOPUP) {
                $topupTotal += $amount;
            } elseif ($tx->type === AgencyAdvanceTransaction::TYPE_USAGE) {
                // usage is negative in ledger → totals should be positive
                $usageTotal += abs($amount);
            } elseif ($tx->type === AgencyAdvanceTransaction::TYPE_CORRECTION) {
                $correctionTotal += $amount;
            }

            $rows[] = [
                'date' => $tx->created_at?->format('d.m.Y. H:i') ?? '',
                'type' => (string) $tx->type,
                'description' => \App\Support\AdvanceLedgerNote::label($tx->note) ?: (string) ($tx->note ?? ''),
                'amount' => $amount,
                'balance_after' => $running,
            ];
        }

        $closing = $running;

        return [
            'opening_balance' => $opening,
            'rows' => $rows,
            'totals' => [
                'topup_total' => $topupTotal,
                'usage_total' => $usageTotal,
                'correction_total' => $correctionTotal,
            ],
            'closing_balance' => $closing,
        ];
    }

    /**
     * @return 'sent'|'skipped'|'failed'
     */
    public function sendForAgencyYear(User $agency, int $year): string
    {
        $email = (string) ($agency->email ?? '');
        if ($email === '') {
            return 'failed';
        }

        // Claim idempotency row first; if sending fails, delete claim to allow retry.
        $statement = null;
        try {
            $statement = AgencyAdvanceYearlyStatement::query()->create([
                'agency_user_id' => $agency->id,
                'year' => $year,
                'sent_at' => null,
                'email' => null,
            ]);
        } catch (Throwable) {
            Log::channel('payments')->info('advance_yearly_statement_skipped', [
                'agency_user_id' => $agency->id,
                'year' => $year,
                'reason' => 'already_exists',
            ]);
            return 'skipped';
        }

        try {
            $data = $this->buildForAgencyYear($agency, $year);

            $pdfBinary = $this->pdf->renderBinary(
                $agency,
                $year,
                (float) $data['opening_balance'],
                $data['rows'],
                $data['totals'],
                (float) $data['closing_balance'],
            );

            Mail::to($email)->send(new AgencyAdvanceYearlyStatementMail($agency, $year, $pdfBinary));

            $statement->update([
                'sent_at' => now(),
                'email' => $email,
            ]);

            Log::channel('payments')->info('advance_yearly_statement_sent', [
                'agency_user_id' => $agency->id,
                'year' => $year,
                'email' => $email,
            ]);

            return 'sent';
        } catch (Throwable $e) {
            Log::channel('payments')->warning('advance_yearly_statement_failed', [
                'agency_user_id' => $agency->id,
                'year' => $year,
                'email' => $email,
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            if ($statement) {
                DB::transaction(function () use ($statement): void {
                    $fresh = AgencyAdvanceYearlyStatement::query()->whereKey($statement->id)->lockForUpdate()->first();
                    $fresh?->delete();
                });
            }

            return 'failed';
        }
    }
}

