<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Exceptions\NoCapacityException;
use App\Helpers\LocaleHelper;
use App\Http\Requests\CheckoutReservationRequest;
use App\Models\DailyParkingData;
use App\Models\ListOfTimeSlot;
use App\Models\Reservation;
use App\Models\TempData;
use App\Models\Vehicle;
use App\Models\User;
use App\Models\AgencyAdvanceTransaction;
use App\Services\Payment\PaymentSuccessHandler;
use App\Services\Reservation\DuplicateReservationAttemptService;
use App\Services\Reservation\FreeReservationRules;
use App\Support\CheckoutResultFlash;
use App\Support\QueueMode;
use App\Support\ReservationInvoiceAmount;
use App\Support\UiText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use App\Jobs\ProcessReservationAfterPaymentJob;

/**
 * Checkout: validacija, dostupnost, temp_data (pending), sync createSession, redirect na banku.
 *
 * Namjerno V1: createSession se poziva sync u web requestu (nema queue job-a za init).
 * V. docs/payment-v1-production-audit.md i docs/payment-architecture.md.
 *
 * Race condition: lock na daily_parking_data; rezervacija tek nakon SUCCESS callbacka.
 * Dupli klik: postojeći pending za slot+user → isti payment link.
 * Free rezervacije: bez createSession; PaymentSuccessHandler bez fiskalizacije; redirect na guest.reserve / panel.reservations.
 */
class CheckoutController extends Controller
{
    /**
     * Validira. Atomic claim: transakcija + lockForUpdate na daily_parking_data, provera kapaciteta,
     * kreiranje temp_data, increment pending. createSession van transakcije.
     */
    public function store(
        CheckoutReservationRequest $request,
        PaymentService $paymentService,
        PaymentSuccessHandler $successHandler,
        DuplicateReservationAttemptService $duplicateAttemptService,
    ): RedirectResponse|Response|JsonResponse {
        $date = $request->validated('reservation_date');
        $dropOffSlotId = (int) $request->validated('drop_off_time_slot_id');
        $pickUpSlotId = (int) $request->validated('pick_up_time_slot_id');

        $arrivalSlot = ListOfTimeSlot::query()->find($dropOffSlotId);
        $departureSlot = ListOfTimeSlot::query()->find($pickUpSlotId);
        if (! $arrivalSlot || ! $departureSlot) {
            return $request->expectsJson()
                ? response()->json(['message' => __('Invalid time slots.')], 422)
                : response(__('Invalid time slots.'), 422);
        }

        $isFree = FreeReservationRules::isFreeReservation($arrivalSlot, $departureSlot);
        $snapshot = $this->resolveSnapshotInput($request);
        $panelAuthBooking = $request->user() !== null && $request->boolean('auth_panel_booking');
        $paymentMethod = $panelAuthBooking ? (string) ($request->validated('payment_method') ?? 'card') : 'card';

        // Agency advance payment (feature-flagged). Only for authenticated panel booking; never for guest.
        if ($panelAuthBooking && $paymentMethod === 'advance' && ! $isFree) {
            if (! (bool) config('features.advance_payments')) {
                $msg = 'Avansno plaćanje trenutno nije dostupno.';
                return back()->withInput()->with('error', $msg)->withErrors(['payment_method' => $msg]);
            }

            $invoiceAmount = ReservationInvoiceAmount::snapshotForNewReservation('paid', $snapshot['vehicle_type_id'] ?? null);
            $userId = (int) $request->user()->id;

            // Allow frontend-supplied merchant_transaction_id for double-submit idempotency.
            $merchantTransactionId = $request->validated('merchant_transaction_id');
            $merchantTransactionId = is_string($merchantTransactionId) && trim($merchantTransactionId) !== ''
                ? trim($merchantTransactionId)
                : Str::uuid()->toString();

            try {
                $reservation = DB::transaction(function () use ($date, $dropOffSlotId, $pickUpSlotId, $snapshot, $invoiceAmount, $userId, $merchantTransactionId) {
                    // Lock agency user row to serialize advance spending (prevents double-spend).
                    User::query()->whereKey($userId)->lockForUpdate()->first();

                    $slotIds = array_values(array_unique([(int) $dropOffSlotId, (int) $pickUpSlotId]));
                    $dailyRows = DailyParkingData::query()
                        ->whereDate('date', $date)
                        ->whereIn('time_slot_id', $slotIds)
                        ->lockForUpdate()
                        ->get()
                        ->keyBy('time_slot_id');

                    foreach ($slotIds as $slotId) {
                        $daily = $dailyRows->get($slotId);
                        if (! $daily || $daily->is_blocked || $daily->availableCapacity() < 1) {
                            throw new NoCapacityException(__('No availability for selected slot.'));
                        }
                    }

                    // Balance under lock.
                    $balance = (float) (DB::table('agency_advance_transactions')
                        ->where('agency_user_id', $userId)
                        ->selectRaw('COALESCE(SUM(amount), 0) as s')
                        ->value('s') ?? 0);
                    $need = (float) $invoiceAmount;
                    if ($balance + 0.000001 < $need) {
                        $msg = 'Raspoloživi avans nije dovoljan za ovu rezervaciju. Možete izabrati plaćanje karticom ili izvršiti avansnu uplatu.';
                        abort(422, $msg);
                    }

                    // Create reservation paid immediately (no temp_data, no bank).
                    $reservation = Reservation::create([
                        'user_id' => $userId,
                        'vehicle_id' => $snapshot['vehicle_id'],
                        'merchant_transaction_id' => $merchantTransactionId,
                        'payment_method' => 'advance',
                        'drop_off_time_slot_id' => (int) $dropOffSlotId,
                        'pick_up_time_slot_id' => (int) $pickUpSlotId,
                        'reservation_date' => $date,
                        'user_name' => $snapshot['user_name'],
                        'country' => $snapshot['country'],
                        'license_plate' => $snapshot['license_plate'],
                        'vehicle_type_id' => $snapshot['vehicle_type_id'],
                        'email' => $snapshot['email'],
                        'preferred_locale' => $snapshot['preferred_locale'] ?? app()->getLocale(),
                        'status' => 'paid',
                        'invoice_amount' => $invoiceAmount,
                        'email_sent' => Reservation::EMAIL_NOT_SENT,
                        'created_by_admin' => false,
                    ]);

                    // Consume capacity immediately.
                    foreach ($slotIds as $slotId) {
                        /** @var DailyParkingData|null $row */
                        $row = $dailyRows->get($slotId);
                        if ($row) {
                            $row->increment('reserved');
                        }
                    }

                    // Ledger usage (negative).
                    AgencyAdvanceTransaction::query()->create([
                        'agency_user_id' => $userId,
                        'amount' => number_format(-1 * (float) $invoiceAmount, 2, '.', ''),
                        'type' => AgencyAdvanceTransaction::TYPE_USAGE,
                        'reference_type' => 'reservation',
                        'reference_id' => (int) $reservation->id,
                        'merchant_transaction_id' => $reservation->merchant_transaction_id,
                        'note' => \App\Support\AdvanceLedgerNote::KEY_RESERVATION_PAYMENT,
                    ]);

                    return $reservation;
                });
            } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
                // Double submit with same merchant_transaction_id: return existing reservation.
                $existing = Reservation::query()->where('merchant_transaction_id', $merchantTransactionId)->first();
                if ($existing) {
                    $reservation = $existing;
                } else {
                    throw $e;
                }
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
                if ($e->getStatusCode() === 422) {
                    return back()->withInput()->with('error', $e->getMessage())->withErrors(['payment_method' => $e->getMessage()]);
                }
                throw $e;
            } catch (NoCapacityException $e) {
                $message = $e->getMessage();
                return $request->expectsJson()
                    ? response()->json(['message' => $message], 422)
                    : response($message, 422);
            }

            // Dispatch invoice/fiscal pipeline as for paid reservations.
            $bankFake = (string) config('services.bank.driver', 'fake') === 'fake';
            $fiscalFake = (string) config('services.fiscalization.driver', 'fake') === 'fake';
            if ($bankFake && $fiscalFake) {
                QueueMode::dispatchForFakeE2e(new ProcessReservationAfterPaymentJob($reservation->id));
            } else {
                ProcessReservationAfterPaymentJob::dispatch($reservation->id);
            }

            return redirect()->to(route('panel.reservations', [], false))
                ->with('checkout_banner', CheckoutResultFlash::forReservationSuccess(false, true, false));
        }

        // Business validation (pre-payment): prevent duplicate attempts for same date+plate+same drop OR same pick.
        // NOTE: intentionally does NOT block cross-match (drop=pick / pick=drop).
        if ($duplicateAttemptService->existsConflict($date, (string) $snapshot['license_plate'], $dropOffSlotId, $pickUpSlotId)) {
            $message = 'Već postoji rezervacija za ovaj datum, odabrani termin i ovu registarsku tablicu. Proverite podatke ili kontaktirajte podršku.';

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : back()->withInput()->with('error', $message)->withErrors(['reservation' => $message]);
        }

        // Već postoji pending za iste slotove + user/email
        $existingBySlot = $this->findExistingPendingForSlot($request, $date, $dropOffSlotId, $pickUpSlotId);
        if ($existingBySlot) {
            $exArrival = ListOfTimeSlot::query()->find($existingBySlot->drop_off_time_slot_id);
            $exDeparture = ListOfTimeSlot::query()->find($existingBySlot->pick_up_time_slot_id);
            if ($exArrival && $exDeparture && FreeReservationRules::isFreeReservation($exArrival, $exDeparture)) {
                $created = $successHandler->handle($existingBySlot, ['source' => 'free_checkout', 'context' => 'existing_pending'], false);

                return $this->redirectAfterFreeCheckout($request, $created, $existingBySlot->merchant_transaction_id);
            }

            $session = $paymentService->createSession($existingBySlot);
            if ($session->success && $session->paymentUrl) {
                return redirect()->away($session->paymentUrl);
            }

            Log::channel('payments')->warning('checkout_create_session_failed', [
                'stage' => 'checkout_existing_pending',
                'merchant_transaction_id' => $existingBySlot->merchant_transaction_id,
                'temp_data_id' => $existingBySlot->id,
            ]);

            return $this->createSessionFailedResponse($request, $session);
        }

        // merchant_transaction_id i retry_token uvek generiše backend (pre job-a i redirect-a)
        $merchantTransactionId = Str::uuid()->toString();
        $retryToken = Str::uuid()->toString();

        try {
            $temp = DB::transaction(function () use ($request, $date, $dropOffSlotId, $pickUpSlotId, $merchantTransactionId, $retryToken, $snapshot, $isFree) {
                $slotIds = array_values(array_unique([
                    (int) $dropOffSlotId,
                    $pickUpSlotId,
                ]));

                // Atomic: lock BOTH selected slots (arrival + departure).
                $dailyRows = DailyParkingData::query()
                    ->where('date', $date)
                    ->whereIn('time_slot_id', $slotIds)
                    ->lockForUpdate()
                    ->get()
                    ->keyBy('time_slot_id');

                foreach ($slotIds as $slotId) {
                    $daily = $dailyRows->get($slotId);
                    if (! $daily || $daily->is_blocked || $daily->availableCapacity() < 1) {
                        throw new NoCapacityException(__('No availability for selected slot.'));
                    }
                }

                $preferredLocale = $request->user()
                    ? ($request->user()->lang ?? 'en')
                    : ($request->session()->get('locale') ?: app()->getLocale());
                if (! LocaleHelper::isValid($preferredLocale)) {
                    $preferredLocale = 'en';
                }

                $invoiceSnapshot = ReservationInvoiceAmount::snapshotForNewReservation(
                    $isFree ? 'free' : 'paid',
                    $snapshot['vehicle_type_id'] ?? null
                );

                $temp = TempData::create([
                    'merchant_transaction_id' => $merchantTransactionId,
                    'retry_token' => $retryToken,
                    'user_id' => $request->user()?->id,
                    'vehicle_id' => $snapshot['vehicle_id'],
                    'drop_off_time_slot_id' => $dropOffSlotId,
                    'pick_up_time_slot_id' => $pickUpSlotId,
                    'reservation_date' => $date,
                    'user_name' => $snapshot['user_name'],
                    'country' => $snapshot['country'],
                    'license_plate' => $snapshot['license_plate'],
                    'vehicle_type_id' => $snapshot['vehicle_type_id'],
                    'invoice_amount_snapshot' => $invoiceSnapshot,
                    'email' => $snapshot['email'],
                    'preferred_locale' => $preferredLocale,
                    'status' => TempData::STATUS_PENDING,
                ]);

                // Soft-lock capacity for BOTH selected slots (arrival + departure).
                foreach ($slotIds as $slotId) {
                    /** @var DailyParkingData|null $row */
                    $row = $dailyRows->get($slotId);
                    if ($row) {
                        $row->increment('pending');
                    }
                }

                Log::channel('payments')->info('Payment init', [
                    'merchant_transaction_id' => $merchantTransactionId,
                    'temp_data_id' => $temp->id,
                    'user_id' => $temp->user_id,
                    'reservation_id' => null,
                    'retry_token' => $retryToken,
                ]);

                return $temp;
            });
        } catch (NoCapacityException $e) {
            $message = $e->getMessage();

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : response($message, 422);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            // Dupli klik: drugi request ubacio isti merchant_transaction_id → koristi postojeći red
            $existingByMtid = TempData::where('merchant_transaction_id', $merchantTransactionId)
                ->where('status', TempData::STATUS_PENDING)
                ->first();
            if ($existingByMtid) {
                $exA = ListOfTimeSlot::query()->find($existingByMtid->drop_off_time_slot_id);
                $exD = ListOfTimeSlot::query()->find($existingByMtid->pick_up_time_slot_id);
                if ($exA && $exD && FreeReservationRules::isFreeReservation($exA, $exD)) {
                    $created = $successHandler->handle($existingByMtid, ['source' => 'free_checkout', 'context' => 'idempotent'], false);

                    return $this->redirectAfterFreeCheckout($request, $created, $existingByMtid->merchant_transaction_id);
                }

                $session = $paymentService->createSession($existingByMtid);
                if ($session->success && $session->paymentUrl) {
                    return redirect()->away($session->paymentUrl);
                }

                Log::channel('payments')->warning('checkout_create_session_failed', [
                    'stage' => 'checkout_after_unique_violation',
                    'merchant_transaction_id' => $existingByMtid->merchant_transaction_id,
                    'temp_data_id' => $existingByMtid->id,
                ]);

                return $this->createSessionFailedResponse($request, $session);
            }
            throw $e;
        }

        if ($isFree) {
            $created = $successHandler->handle($temp, ['source' => 'free_checkout'], false);

            return $this->redirectAfterFreeCheckout($request, $created, $temp->merchant_transaction_id);
        }

        $session = $paymentService->createSession($temp);

        if ($session->success && $session->paymentUrl) {
            return redirect()->away($session->paymentUrl);
        }

        // createSession nije uspeo → vrati hold (decrement pending)
        $slotIds = array_values(array_unique([
            (int) $dropOffSlotId,
            $pickUpSlotId,
        ]));
        DailyParkingData::query()
            ->where('date', $date)
            ->whereIn('time_slot_id', $slotIds)
            ->decrement('pending');

        Log::channel('payments')->warning('checkout_create_session_failed', [
            'stage' => 'checkout_after_temp_created',
            'merchant_transaction_id' => $temp->merchant_transaction_id,
            'temp_data_id' => $temp->id,
        ]);

        return $this->createSessionFailedResponse($request, $session);
    }

    private function createSessionFailedResponse(
        CheckoutReservationRequest $request,
        ?PaymentSessionResult $session = null,
    ): JsonResponse|Response {
        $message = $session?->errorMessage
            ?? UiText::t('payment', 'payment_processing_issue', 'Payment temporarily unavailable.');

        return $request->expectsJson()
            ? response()->json(['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE)
            : response($message, Response::HTTP_SERVICE_UNAVAILABLE);
    }

    private function redirectAfterFreeCheckout(CheckoutReservationRequest $request, bool $created, ?string $merchantTransactionId = null): RedirectResponse
    {
        $url = $request->user()
            ? route('panel.reservations', [], false)
            : route('guest.reserve', [], false);

        if (! $created && is_string($merchantTransactionId) && $merchantTransactionId !== ''
            && Reservation::where('merchant_transaction_id', $merchantTransactionId)->exists()) {
            $created = true;
        }

        if (! $created) {
            return redirect()->to($url)->with('checkout_banner', CheckoutResultFlash::freeReservationFailed());
        }

        return redirect()->to($url)->with(
            'checkout_banner',
            CheckoutResultFlash::forReservationSuccess(true, true),
        );
    }

    /** Pending temp_data za isti slot (reservation_date + drop_off) i isti user (user_id ili email). */
    private function findExistingPendingForSlot(
        CheckoutReservationRequest $request,
        string $date,
        int $dropOffSlotId,
        int $pickUpSlotId,
    ): ?TempData {
        $query = TempData::where('reservation_date', $date)
            ->where('drop_off_time_slot_id', $dropOffSlotId)
            ->where('pick_up_time_slot_id', $pickUpSlotId)
            ->where('status', TempData::STATUS_PENDING);

        if ($request->user()) {
            $query->where('user_id', $request->user()->id);
        } else {
            $query->where('email', $request->validated('email'));
        }

        return $query->first();
    }

    /**
     * Snapshot source of truth: auth user can choose saved vehicle, otherwise manual form input.
     *
     * @return array{vehicle_id:int|null,user_name:string,country:string,license_plate:string,vehicle_type_id:int,email:string}
     */
    private function resolveSnapshotInput(CheckoutReservationRequest $request): array
    {
        $vehicleId = $request->validated('vehicle_id');
        if ($request->user() && $vehicleId) {
            $vehicle = Vehicle::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($vehicleId);

            return [
                'vehicle_id' => $vehicle->id,
                'user_name' => (string) ($request->user()->name ?? ''),
                'country' => (string) ($request->user()->country ?? ''),
                'license_plate' => $vehicle->license_plate,
                'vehicle_type_id' => (int) $vehicle->vehicle_type_id,
                'email' => (string) ($request->user()->email ?? ''),
            ];
        }

        return [
            'vehicle_id' => null,
            'user_name' => (string) $request->validated('name'),
            'country' => (string) $request->validated('country'),
            'license_plate' => (string) $request->validated('license_plate'),
            'vehicle_type_id' => (int) $request->validated('vehicle_type_id'),
            'email' => (string) $request->validated('email'),
        ];
    }
}
