<?php

namespace App\Console\Commands;

use App\Exceptions\AmbiguousFreeReservationLinkException;
use App\Exceptions\FreeReservationLinkedToOtherRequestException;
use App\Models\FreeReservationRequest;
use App\Services\AdminPanel\FreeReservation\FreeReservationRequestFulfillmentService;
use Illuminate\Console\Command;
use Throwable;

class RepairFulfilledFreeReservationRequests extends Command
{
    protected $signature = 'free-reservation-requests:repair-fulfilled
                            {--dry-run : Only report what would be repaired}
                            {--id= : Repair a single request id}';

    protected $description = 'Complete submitted free reservation requests that already have matching free reservations. Production: run per id (--id=2) or scan all; fixes stuck submitted + wrong FK when match is unambiguous.';

    public function handle(FreeReservationRequestFulfillmentService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $singleId = $this->option('id');

        $query = FreeReservationRequest::query()
            ->whereIn('status', [
                FreeReservationRequest::STATUS_SUBMITTED,
                FreeReservationRequest::STATUS_UPDATED,
            ])
            ->with(['segments.vehicles'])
            ->orderBy('id');

        if ($singleId !== null && $singleId !== '') {
            $query->whereKey((int) $singleId);
        }

        $repaired = 0;
        $skipped = 0;

        foreach ($query->cursor() as $req) {
            try {
                $result = $service->repairSubmittedRequest($req, $dryRun);
                $wouldChange = $result['linked_existing'] > 0
                    || $result['created_new'] > 0
                    || ($dryRun && $result['idempotent']);

                if ($wouldChange) {
                    $repaired++;
                    $this->line(sprintf(
                        'Request #%d: linked=%d created=%d mail_sent=%s%s',
                        $req->id,
                        $result['linked_existing'],
                        $result['created_new'],
                        $result['mail_sent'] ? 'yes' : 'no',
                        $dryRun ? ' (dry-run)' : ''
                    ));
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

        $this->info(sprintf(
            'Done. repaired=%d skipped=%d%s',
            $repaired,
            $skipped,
            $dryRun ? ' (dry-run)' : ''
        ));

        return self::SUCCESS;
    }
}
