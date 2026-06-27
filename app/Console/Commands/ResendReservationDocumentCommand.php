<?php

namespace App\Console\Commands;

use App\Services\Reservation\ReservationDocumentResendService;
use Illuminate\Console\Command;

/**
 * Queue a reservation document email (invoice or free confirmation) by reservation id.
 */
class ResendReservationDocumentCommand extends Command
{
    protected $signature = 'mail:resend-reservation-document {--id= : Reservation id}';

    protected $description = 'Regenerate PDF and queue customer document email for a reservation';

    public function handle(ReservationDocumentResendService $resend): int
    {
        $id = $this->option('id');
        if ($id === null || $id === '' || ! is_numeric($id)) {
            $this->error('Provide --id=<reservation_id>');

            return self::FAILURE;
        }

        $result = $resend->queue((int) $id);

        return match ($result) {
            'queued' => $this->infoThenSuccess("Document email queued for reservation #{$id}."),
            'not_found' => $this->errorThenFailure("Reservation #{$id} not found."),
            'unsupported_status' => $this->errorThenFailure("Reservation #{$id} is not paid or free."),
        };
    }

    private function infoThenSuccess(string $message): int
    {
        $this->info($message);

        return self::SUCCESS;
    }

    private function errorThenFailure(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }
}
