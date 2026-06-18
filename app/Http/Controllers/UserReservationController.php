<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateReservationVehicleRequest;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\Reservation;
use App\Models\Vehicle;
use App\Services\Reservation\VehicleReplacementCandidateService;
use App\Services\Pdf\FreeReservationPdfGenerator;
use App\Services\Pdf\PaidInvoicePdfGenerator;
use App\Services\Reservation\ReservationBookingPageData;
use App\Services\AgencyAdvance\AgencyAdvanceService;
use App\Support\UiText;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class UserReservationController extends Controller
{
    public function index(Request $request, ReservationBookingPageData $bookingPageData): View
    {
        $booking = $bookingPageData->forAuthenticated($request, $request->user());

        $booking['advanceBalance'] = null;

        if ((bool) config('features.advance_payments')) {
            $balance = app(AgencyAdvanceService::class)->balance((int) $request->user()->id);
            $price = (string) ($booking['paid_amount'] ?? '0.00');
            $canPay = is_numeric($balance) && is_numeric($price) && ((float) $balance + 0.000001) >= (float) $price;
            $booking['advance_balance'] = $balance;
            $booking['advance_can_pay'] = $canPay;
            $booking['advanceBalance'] = $balance;
        }

        return view('panel.reservations', $booking);
    }

    public function downloadInvoice(Request $request, int $id): StreamedResponse
    {
        $reservation = Reservation::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();

        $binary = $this->pdfBinaryForReservation($reservation);
        abort_if($binary === '', 404);

        return response()->streamDownload(
            static function () use ($binary): void {
                echo $binary;
            },
            $this->pdfFilenameForReservation($reservation),
            [
                'Content-Type' => 'application/pdf',
            ]
        );
    }

    /**
     * PDF u browser tabu (inline), generisan na zahtev.
     */
    public function showInvoice(Request $request, int $id): \Illuminate\Http\Response
    {
        $reservation = Reservation::query()
            ->where('user_id', $request->user()->id)
            ->whereKey($id)
            ->firstOrFail();

        $binary = $this->pdfBinaryForReservation($reservation);
        abort_if($binary === '', 404);

        return response($binary, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$this->pdfFilenameForReservation($reservation).'"',
        ]);
    }

    private function pdfBinaryForReservation(Reservation $reservation): string
    {
        if ($reservation->status === 'free') {
            try {
                return app(FreeReservationPdfGenerator::class)->renderBinary($reservation);
            } catch (\Throwable $e) {
                report($e);
                abort(503);
            }
        }
        if ($reservation->status === 'paid') {
            $isFiscal = $reservation->fiscal_jir !== null;
            try {
                return app(PaidInvoicePdfGenerator::class)->renderBinary($reservation, $isFiscal);
            } catch (\Throwable $e) {
                report($e);
                abort(503);
            }
        }

        abort(404);
    }

    private function pdfFilenameForReservation(Reservation $reservation): string
    {
        return $reservation->status === 'free'
            ? $reservation->freeConfirmationPdfFilename()
            : $reservation->invoicePdfFilename();
    }

    public function updateVehicle(UpdateReservationVehicleRequest $request, int $id): RedirectResponse
    {
        $user = $request->user();
        $newVehicleId = (int) $request->validated('vehicle_id');

        /** @var Reservation $reservation */
        $reservation = Reservation::query()
            ->where('user_id', $user->id)
            ->whereKey($id)
            ->with(['pickUpTimeSlot', 'dropOffTimeSlot', 'vehicleType'])
            ->firstOrFail();

        /** @var Vehicle $vehicle */
        $vehicle = Vehicle::query()
            ->where('user_id', $user->id)
            ->whereKey($newVehicleId)
            ->with('vehicleType')
            ->firstOrFail();

        $svc = app(VehicleReplacementCandidateService::class);

        DB::transaction(function () use ($user, $reservation, $vehicle, $svc): void {
            // Lock reservation row + all same-date reservations for selected vehicle to prevent races.
            $lockedReservation = Reservation::query()
                ->where('user_id', $user->id)
                ->whereKey($reservation->id)
                ->lockForUpdate()
                ->firstOrFail();

            if (! \App\Services\Reservation\PanelReservationListService::allowsPlateChange($lockedReservation)) {
                abort(422);
            }

            $categoryPrice = (float) ($lockedReservation->vehicleType?->price ?? 0);
            $newPrice = (float) ($vehicle->vehicleType?->price ?? 0);
            if ($newPrice > $categoryPrice + 0.000001) {
                abort(422);
            }

            $date = $lockedReservation->reservation_date?->toDateString() ?? '';
            $sameDate = Reservation::query()
                ->where('user_id', $user->id)
                ->where('vehicle_id', $vehicle->id)
                ->whereDate('reservation_date', $date)
                ->lockForUpdate()
                ->get();

            // Re-check conflict rule under lock.
            if ($svc->hasConflictWithUpcoming($user, $vehicle->id, $lockedReservation, ignoreReservationIds: [$lockedReservation->id])) {
                abort(422);
            }

            $lockedReservation->update([
                'vehicle_id' => $vehicle->id,
                'license_plate' => $vehicle->license_plate,
                'vehicle_type_id' => $vehicle->vehicle_type_id,
            ]);
        });

        $reservation->refresh();

        if ($reservation->status === 'paid') {
            $reservation->update([
                'invoice_sent_at' => null,
                'email_sent' => Reservation::EMAIL_NOT_SENT,
            ]);
            $reservation->refresh();

            $isFiscal = $reservation->fiscal_jir !== null;
            SendInvoiceEmailJob::dispatch($reservation->id, $isFiscal);
        }

        $locale = app()->getLocale();
        $messageKey = $reservation->status === 'paid' ? 'vehicle_change_success' : 'vehicle_change_success_free';

        return redirect()
            ->route('panel.upcoming')
            ->with(
                'message',
                UiText::t(
                    'panel',
                    $messageKey,
                    $reservation->status === 'paid'
                        ? 'Vehicle updated. A new invoice will be sent by email.'
                        : 'Vehicle updated.',
                    $locale
                )
            );
    }
}
