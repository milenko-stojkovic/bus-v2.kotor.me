<?php

namespace App\Console\Commands;

use App\Exceptions\AmbiguousFreeReservationLinkException;
use App\Exceptions\FreeReservationLinkedToOtherRequestException;
use App\Models\FreeReservationRequest;
use App\Models\Reservation;
use App\Services\AdminPanel\FreeReservation\FreeReservationRequestFulfillmentService;
use Illuminate\Console\Command;
use Throwable;

class RepairFulfilledFreeReservationRequests extends Command
{
    protected $signature = 'free-reservation-requests:repair-fulfilled
                            {--dry-run : Only report what would be repaired}
                            {--id= : Repair a single request id}
                            {--resend-email : Resend fulfillment confirmation even when email_sent=1}';

    protected $description = 'Complete submitted free reservation requests that already have matching free reservations. Production: run per id (--id=2) or scan all; fixes stuck submitted + wrong FK when match is unambiguous. Also sends missing fulfillment confirmation emails for fulfilled requests.';

    public function handle(FreeReservationRequestFulfillmentService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $singleId = $this->option('id');
        $resendEmail = (bool) $this->option('resend-email');

        $repaired = 0;
        $skipped = 0;

        $submittedQuery = FreeReservationRequest::query()
            ->whereIn('status', [
                FreeReservationRequest::STATUS_SUBMITTED,
                FreeReservationRequest::STATUS_UPDATED,
            ])
            ->with(['segments.vehicles'])
            ->orderBy('id');

        if ($singleId !== null && $singleId !== '') {
            $submittedQuery->whereKey((int) $singleId);
        }

        foreach ($submittedQuery->cursor() as $req) {
            try {
                $result = $service->repairSubmittedRequest($req, $dryRun);
                $wouldChange = $result['linked_existing'] > 0
                    || $result['created_new'] > 0
                    || ($dryRun && $result['idempotent']);

                if ($wouldChange) {
                    $repaired++;
                    $this->line($this->formatSubmittedLine($req->id, $result, $dryRun));
                } else {
                    $skipped++;
                }
            } catch (AmbiguousFreeReservationLinkException|FreeReservationLinkedToOtherRequestException $e) {
                $skipped++;
                $this->warn('Request #'.$req->id.': skipped — '.$e->getMessage());
            } catch (Throwable $e) {
                $skipped++;
                $this->error('Request #'.$req->id.': failed — '.$e->getMessage());
            }
        }

        $fulfilledQuery = FreeReservationRequest::query()
            ->where('status', FreeReservationRequest::STATUS_FULFILLED)
            ->with(['reservations', 'segments.vehicles'])
            ->orderBy('id');

        if ($singleId !== null && $singleId !== '') {
            $fulfilledQuery->whereKey((int) $singleId);
        } elseif (! $resendEmail) {
            $fulfilledQuery->whereHas('reservations', function ($q): void {
                $q->where('email_sent', Reservation::EMAIL_NOT_SENT);
            });
        }

        foreach ($fulfilledQuery->cursor() as $req) {
            try {
                $result = $service->repairFulfilledRequest($req, $dryRun, $resendEmail);
                $wouldSend = (bool) ($result['would_send'] ?? false);
                $sent = (bool) $result['mail_sent'];
                $skippedAlready = (bool) $result['mail_skipped_already_sent'];

                if ($wouldSend || $sent || ($dryRun && $wouldSend)) {
                    $repaired++;
                    $this->line($this->formatFulfilledLine($req->id, $result, $dryRun));
                } elseif ($skippedAlready) {
                    $skipped++;
                    if ($singleId !== null && $singleId !== '') {
                        $this->line(sprintf(
                            'Request #%d: mail_skipped=already_sent (use --resend-email to force)%s',
                            $req->id,
                            $dryRun ? ' (dry-run)' : ''
                        ));
                    }
                } else {
                    $skipped++;
                }
            } catch (Throwable $e) {
                $skipped++;
                $this->error('Request #'.$req->id.': failed — '.$e->getMessage());
            }
        }

        $this->info(sprintf(
            'Done. repaired=%d skipped=%d%s',
            $repaired,
            $skipped,
            $dryRun ? ' (dry-run)' : ''
        ));

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function formatSubmittedLine(int $requestId, array $result, bool $dryRun): string
    {
        return sprintf(
            'Request #%d: linked=%d created=%d mail_sent=%s mail_skipped=%s%s',
            $requestId,
            $result['linked_existing'],
            $result['created_new'],
            $result['mail_sent'] ? 'yes' : 'no',
            ($result['mail_skipped_already_sent'] ?? false) ? 'yes' : 'no',
            $dryRun ? ' (dry-run)' : ''
        );
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function formatFulfilledLine(int $requestId, array $result, bool $dryRun): string
    {
        $mailStatus = 'no';
        if ($result['mail_sent'] ?? false) {
            $mailStatus = 'yes';
        } elseif ($result['would_send'] ?? false) {
            $mailStatus = 'would_send';
        }

        return sprintf(
            'Request #%d (fulfilled): mail_sent=%s mail_skipped=%s%s',
            $requestId,
            $mailStatus,
            ($result['mail_skipped_already_sent'] ?? false) ? 'yes' : 'no',
            $dryRun ? ' (dry-run)' : ''
        );
    }
}
