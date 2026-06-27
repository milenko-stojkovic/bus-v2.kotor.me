<?php

namespace App\Console\Commands;

use App\Mail\ScheduledAdminReportsMail;
use App\Models\AdminAlert;
use App\Models\ReportEmail;
use App\Models\ScheduledReportDelivery;
use App\Services\AdminPanel\Reports\AdminReportsService;
use App\Services\Pdf\AdminReportsPdfGenerator;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SendScheduledAdminReports extends Command
{
    protected $signature = 'reports:send-scheduled {period : daily|monthly|yearly}';

    protected $description = 'Send scheduled admin PDF reports (daily/monthly/yearly) to report_emails recipients.';

    public function handle(AdminReportsService $reports, AdminReportsPdfGenerator $pdf): int
    {
        $periodType = (string) $this->argument('period');
        if (! in_array($periodType, ['daily', 'monthly', 'yearly'], true)) {
            $this->error('Invalid period. Expected daily|monthly|yearly.');
            return self::FAILURE;
        }

        $tz = 'Europe/Podgorica';
        [$from, $to, $subjectLabel, $fileLabel] = $this->resolvePeriod($periodType, $tz);

        $periodStart = $from->toDateString();
        $periodEnd = $to->toDateString();
        $recipientEmails = $this->normalizedReportRecipientEmails();

        if ($recipientEmails->isEmpty()) {
            Log::channel('payments')->info('scheduled_reports_no_recipients', [
                'period_type' => $periodType,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
            ]);
            $this->info('No recipients found in report_emails; skipped.');

            return self::SUCCESS;
        }

        // Generate all PDFs first (no partial emails).
        try {
            $attachments = $this->buildAllPdfs($reports, $pdf, $periodType, $from, $to, $fileLabel, $subjectLabel);
        } catch (Throwable $e) {
            $this->onPackageFailure(
                stage: 'generation',
                periodType: $periodType,
                from: $from,
                to: $to,
                error: $e->getMessage(),
                exceptionClass: $e::class
            );
            return self::FAILURE;
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $failedRecipients = [];
        $skippedRecipients = [];

        foreach ($recipientEmails as $email) {
            $claim = $this->claimDelivery($periodType, $periodStart, $periodEnd, $email);
            if ($claim['delivery'] === null) {
                $skipped++;
                $skippedRecipients[] = [
                    'email' => $email,
                    'reason' => $claim['skip_reason'] ?? 'already_sent',
                    'sent_at' => $claim['sent_at'] ?? null,
                ];
                Log::channel('payments')->info('scheduled_reports_delivery_skipped', [
                    'period_type' => $periodType,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'recipient_email' => $email,
                    'reason' => $claim['skip_reason'] ?? 'already_sent',
                    'sent_at' => $claim['sent_at'] ?? null,
                ]);
                continue;
            }

            $delivery = $claim['delivery'];

            $subject = $this->subjectFor($periodType, $subjectLabel);
            $body = $this->bodyFor($periodType, $subjectLabel);

            try {
                Mail::to($email)->send(new ScheduledAdminReportsMail(
                    subjectLine: $subject,
                    bodyText: $body,
                    pdfAttachments: $attachments,
                ));

                $delivery->update([
                    'status' => ScheduledReportDelivery::STATUS_SENT,
                    'sent_at' => now(),
                    'error_message' => null,
                ]);

                $sent++;
                Log::channel('payments')->info('scheduled_reports_delivery_sent', [
                    'period_type' => $periodType,
                    'period_start' => $from->toDateString(),
                    'period_end' => $to->toDateString(),
                    'recipient_email' => $email,
                    'attachments' => array_map(fn (array $a) => $a['filename'], $attachments),
                ]);
            } catch (Throwable $e) {
                $delivery->update([
                    'status' => ScheduledReportDelivery::STATUS_FAILED,
                    'sent_at' => null,
                    'error_message' => mb_substr($e->getMessage(), 0, 2000),
                ]);

                $failed++;
                $failedRecipients[] = [
                    'email' => $email,
                    'error' => $e->getMessage(),
                    'exception' => $e::class,
                ];
                Log::channel('payments')->warning('scheduled_reports_delivery_failed', [
                    'period_type' => $periodType,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'recipient_email' => $email,
                    'message' => $e->getMessage(),
                    'exception' => $e::class,
                ]);
            }
        }

        if ($failed > 0) {
            $this->onPackageFailure(
                stage: 'delivery',
                periodType: $periodType,
                from: $from,
                to: $to,
                error: collect($failedRecipients)
                    ->map(fn (array $row): string => sprintf(
                        '%s (%s: %s)',
                        $row['email'],
                        $row['exception'],
                        mb_substr((string) $row['error'], 0, 200),
                    ))
                    ->implode('; '),
                exceptionClass: 'ScheduledReportDeliveryFailure'
            );
        }

        $this->info(sprintf(
            'scheduled reports done: sent=%d, skipped=%d, failed=%d, recipients=%d',
            $sent,
            $skipped,
            $failed,
            $recipientEmails->count(),
        ));

        foreach ($skippedRecipients as $row) {
            $sentAt = $row['sent_at'] ? ' sent_at='.$row['sent_at'] : '';
            $this->line(sprintf(
                '  skipped: %s (%s%s)',
                $row['email'],
                $row['reason'],
                $sentAt,
            ));
        }

        foreach ($failedRecipients as $row) {
            $this->line(sprintf(
                '  failed: %s (%s: %s)',
                $row['email'],
                $row['exception'],
                mb_substr((string) $row['error'], 0, 200),
            ));
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, string>
     */
    private function normalizedReportRecipientEmails(): \Illuminate\Support\Collection
    {
        return ReportEmail::allRecipients()
            ->pluck('email')
            ->map(fn ($email) => $this->normalizeRecipientEmail((string) $email))
            ->filter(fn (string $email) => $email !== '')
            ->unique()
            ->values();
    }

    private function normalizeRecipientEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    /**
     * @return array{0:Carbon,1:Carbon,2:string,3:string} from,to,subjectLabel,fileLabel
     */
    private function resolvePeriod(string $periodType, string $tz): array
    {
        $now = Carbon::now($tz);

        if ($periodType === 'daily') {
            $d = $now->copy()->subDay()->startOfDay();
            return [$d, $d, $d->format('d.m.Y').'.', $d->format('Y-m-d')];
        }

        if ($periodType === 'monthly') {
            $from = $now->copy()->subMonthNoOverflow()->startOfMonth()->startOfDay();
            $to = $from->copy()->endOfMonth()->startOfDay();
            return [$from, $to, $from->format('m.Y').'.', $from->format('Y-m')];
        }

        // yearly
        $year = (int) $now->copy()->subYear()->format('Y');
        $from = Carbon::create($year, 1, 1, 0, 0, 0, $tz)->startOfDay();
        $to = Carbon::create($year, 12, 31, 0, 0, 0, $tz)->startOfDay();

        return [$from, $to, $from->format('Y').'.', $from->format('Y')];
    }

    /**
     * @return array<int, array{filename:string,binary:string}>
     */
    private function buildAllPdfs(
        AdminReportsService $reports,
        AdminReportsPdfGenerator $pdf,
        string $periodType,
        Carbon $from,
        Carbon $to,
        string $fileLabel,
        string $subjectLabel,
    ): array {
        $prefix = match ($periodType) {
            'daily' => 'dnevni',
            'monthly' => 'mjesečni',
            default => 'godišnji',
        };

        $attachments = [];

        // 1) Po uplati
        $dataset = [
            'title' => 'Finansijski izvještaj po uplati za '.$subjectLabel,
            'subtitle' => 'Uplate za rezervacije',
            'kind' => 'by_payment',
            'period' => $subjectLabel,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'data' => $reports->byPayment($from, $to),
        ];
        $attachments[] = [
            'filename' => "{$prefix}-po-uplati-{$fileLabel}.pdf",
            'binary' => $pdf->renderBinary($dataset),
        ];

        // 2) Obaveze po avansu (feature-flag)
        if ((bool) config('features.advance_payments')) {
            $snapshot = $to->copy()->setTimezone('Europe/Podgorica')->endOfDay();
            $dataset = [
                'title' => 'Izvještaj o obavezama po osnovu avansnih uplata na dan '.$this->fmtDateCg($to),
                'subtitle' => 'Prikaz predstavlja stanje neiskorišćenih avansnih sredstava po agencijama na izabrani dan.',
                'kind' => 'advance_obligations',
                'period' => $this->fmtDateCg($to),
                'from' => $to->toDateString(),
                'to' => $to->toDateString(),
                'data' => $reports->advanceObligationsSnapshot($snapshot),
            ];
            $attachments[] = [
                'filename' => "{$prefix}-obaveze-po-avansu-{$fileLabel}.pdf",
                'binary' => $pdf->renderBinary($dataset),
            ];
        } else {
            Log::channel('payments')->info('scheduled_reports_advance_obligations_skipped_feature_off', [
                'period_type' => $periodType,
                'period_start' => $from->toDateString(),
                'period_end' => $to->toDateString(),
            ]);
        }

        // 3) Po tipu rezervacije
        $dataset = [
            'title' => 'Izvještaj po tipu rezervacije za '.$subjectLabel,
            'subtitle' => 'Prihodi po tipu rezervacije',
            'kind' => 'by_reservation_type',
            'period' => $subjectLabel,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'data' => $reports->byReservationType($from, $to),
        ];
        $attachments[] = [
            'filename' => "{$prefix}-po-tipu-rezervacije-{$fileLabel}.pdf",
            'binary' => $pdf->renderBinary($dataset),
        ];

        // 4) Po tipu vozila
        $dataset = [
            'title' => 'Izvještaj po tipu vozila za '.$subjectLabel,
            'subtitle' => 'Realizovane rezervacije po tipu vozila',
            'kind' => 'by_vehicle_type',
            'period' => $subjectLabel,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'data' => $reports->byVehicleType($from, $to),
        ];
        $attachments[] = [
            'filename' => "{$prefix}-po-tipu-vozila-{$fileLabel}.pdf",
            'binary' => $pdf->renderBinary($dataset),
        ];

        return $attachments;
    }

    private function subjectFor(string $periodType, string $label): string
    {
        return match ($periodType) {
            'daily' => 'Dnevni izvještaji – '.$label,
            'monthly' => 'Mjesečni izvještaji – '.$label,
            default => 'Godišnji izvještaji – '.$label,
        };
    }

    private function bodyFor(string $periodType, string $label): string
    {
        $lines = [
            'Poštovani,',
            '',
            match ($periodType) {
                'daily' => 'U prilogu su dnevni izvještaji za '.$label,
                'monthly' => 'U prilogu su mjesečni izvještaji za '.$label,
                default => 'U prilogu su godišnji izvještaji za '.$label,
            },
            '',
            'Srdačan pozdrav,',
            'Kotor Bus',
        ];

        return implode("\n", $lines);
    }

    private function fmtDateCg(Carbon $d): string
    {
        return $d->format('d.m.Y').'.';
    }

    /**
     * Claim delivery row for this recipient/period. Returns null delivery when already sent.
     *
     * @return array{delivery: ?ScheduledReportDelivery, skip_reason: ?string, sent_at: ?string}
     */
    private function claimDelivery(string $periodType, string $periodStart, string $periodEnd, string $email): array
    {
        $email = $this->normalizeRecipientEmail($email);

        return DB::transaction(function () use ($periodType, $periodStart, $periodEnd, $email): array {
            $row = ScheduledReportDelivery::query()
                ->where('period_type', $periodType)
                ->whereDate('period_start', $periodStart)
                ->whereDate('period_end', $periodEnd)
                ->where('recipient_email', $email)
                ->lockForUpdate()
                ->first();

            if ($row && $row->status === ScheduledReportDelivery::STATUS_SENT) {
                return [
                    'delivery' => null,
                    'skip_reason' => 'already_sent',
                    'sent_at' => $row->sent_at?->toDateTimeString(),
                ];
            }

            if (! $row) {
                $row = ScheduledReportDelivery::query()->create([
                    'period_type' => $periodType,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'recipient_email' => $email,
                    'status' => ScheduledReportDelivery::STATUS_SENDING,
                    'sent_at' => null,
                    'error_message' => null,
                ]);
            } else {
                $row->update([
                    'status' => ScheduledReportDelivery::STATUS_SENDING,
                    'sent_at' => null,
                    'error_message' => null,
                ]);
            }

            return [
                'delivery' => $row,
                'skip_reason' => null,
                'sent_at' => null,
            ];
        });
    }

    private function onPackageFailure(
        string $stage,
        string $periodType,
        Carbon $from,
        Carbon $to,
        string $error,
        string $exceptionClass,
    ): void {
        $title = sprintf(
            'Scheduled admin reports failed (%s): %s – %s',
            $stage,
            $periodType,
            $from->toDateString()
        );
        $message = sprintf(
            'Neuspjeh slanja zakazanih izvještaja (%s) za period %s .. %s. Greška: %s',
            $stage,
            $from->toDateString(),
            $to->toDateString(),
            mb_substr($error, 0, 200)
        );

        // Idempotent alert (do not duplicate on retries).
        $exists = AdminAlert::query()
            ->where('type', 'scheduled_admin_reports_failed')
            ->where('title', $title)
            ->whereNull('removed_at')
            ->exists();
        if (! $exists) {
            AdminAlert::query()->create([
                'type' => 'scheduled_admin_reports_failed',
                'status' => AdminAlert::STATUS_UNREAD,
                'title' => $title,
                'message' => $message,
                'payload_json' => [
                    'stage' => $stage,
                    'period_type' => $periodType,
                    'period_start' => $from->toDateString(),
                    'period_end' => $to->toDateString(),
                    'error_message' => $error,
                    'exception' => $exceptionClass,
                ],
            ]);
        }

        $subject = '[Kotor Bus] Scheduled admin reports failed';
        $body = implode("\n", [
            'Neuspjeh slanja zakazanih admin izvještaja.',
            '',
            'stage: '.$stage,
            'period_type: '.$periodType,
            'period_start: '.$from->toDateString(),
            'period_end: '.$to->toDateString(),
            'error: '.$error,
            'exception: '.$exceptionClass,
        ]);

        try {
            Mail::raw($body, function ($m) use ($subject): void {
                $m->to('bus@kotor.me')
                    ->from(config('mail.from.address'), config('mail.from.name'))
                    ->subject($subject);
            });
        } catch (Throwable $e) {
            Log::channel('payments')->error('scheduled_reports_failure_email_failed', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);
        }

        Log::channel('payments')->error('scheduled_reports_generation_failed', [
            'stage' => $stage,
            'period_type' => $periodType,
            'period_start' => $from->toDateString(),
            'period_end' => $to->toDateString(),
            'message' => $error,
            'exception' => $exceptionClass,
        ]);
    }
}

