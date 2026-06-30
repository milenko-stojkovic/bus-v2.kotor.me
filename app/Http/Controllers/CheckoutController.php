<?php

namespace App\Http\Controllers;

use App\Contracts\PaymentService;
use App\Contracts\PaymentSessionResult;
use App\Exceptions\DuplicateTerminiReservationException;
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
use App\Services\Payment\BankartBillingCountryAlertService;
use App\Services\Payment\CheckoutPaymentInitAlertService;
use App\Services\Payment\PaymentInitFailureService;
use App\Services\Payment\PaymentSuccessHandler;
use App\Services\Payment\PendingBankartRedirectService;
use App\Services\Reservation\DuplicateReservationAttemptService;
use App\Services\Reservation\FreeReservationRules;
use App\Services\Reservation\GuestPaidLowerCategoryCheckoutGuard;
use App\Support\BankartBillingCountry;
use App\Support\CheckoutAuditLogger;
use App\Support\CheckoutResultFlash;
use App\Support\QueueMode;
use App\Support\ReservationInvoiceAmount;
use App\Support\ReservationKind;
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
        if ($request->isDailyTicketBooking()) {
            return $this->storeDailyTicketBooking($request, $paymentService, $successHandler);
        }

        $snapshotEarly = $this->resolveSnapshotInput($request);
        CheckoutAuditLogger::log('checkout_started', CheckoutAuditLogger::contextFromRequest($request, $snapshotEarly));

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

        $existingPendingResponse = $this->tryResumeExistingPendingTimeSlotsCheckout(
            $request,
            $paymentService,
            $successHandler,
            $date,
            $dropOffSlotId,
            $pickUpSlotId,
            $snapshot,
        );
        if ($existingPendingResponse !== null) {
            return $existingPendingResponse;
        }

        $duplicateResponse = $this->duplicateConflictResponse(
            $request,
            $duplicateAttemptService,
            $date,
            (string) $snapshot['license_plate'],
            $dropOffSlotId,
            $pickUpSlotId,
        );
        if ($duplicateResponse !== null) {
            return $duplicateResponse;
        }

        $lowerCategoryBlock = $this->guestLowerCategoryBlockResponse(
            $request,
            $snapshot,
            $date,
            ReservationKind::TIME_SLOTS,
            $isFree,
        );
        if ($lowerCategoryBlock !== null) {
            return $lowerCategoryBlock;
        }

        $billingCountryBlock = $this->bankartBillingCountryBlockResponse(
            $request,
            $snapshot,
            $date,
            ReservationKind::TIME_SLOTS,
            $isFree,
            $paymentMethod,
        );
        if ($billingCountryBlock !== null) {
            return $billingCountryBlock;
        }

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
                $reservation = DB::transaction(function () use ($date, $dropOffSlotId, $pickUpSlotId, $snapshot, $invoiceAmount, $userId, $merchantTransactionId, $duplicateAttemptService, $request) {
                    // Lock agency user row to serialize advance spending (prevents double-spend).
                    User::query()->whereKey($userId)->lockForUpdate()->first();

                    $duplicateAttemptService->assertNoConflict(
                        $date,
                        (string) $snapshot['license_plate'],
                        $dropOffSlotId,
                        $pickUpSlotId,
                        locale: $request->user()?->lang ?? app()->getLocale(),
                    );

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
                        'reservation_kind' => ReservationKind::TIME_SLOTS,
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
            } catch (DuplicateTerminiReservationException $e) {
                return back()->withInput()->with('error', $e->getMessage())->withErrors(['reservation' => $e->getMessage()]);
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

        // merchant_transaction_id i retry_token uvek generiše backend (pre job-a i redirect-a)
        $merchantTransactionId = Str::uuid()->toString();
        $retryToken = Str::uuid()->toString();

        try {
            $temp = DB::transaction(function () use ($request, $date, $dropOffSlotId, $pickUpSlotId, $merchantTransactionId, $retryToken, $snapshot, $isFree, $duplicateAttemptService) {
                $duplicateAttemptService->assertNoConflict(
                    $date,
                    (string) $snapshot['license_plate'],
                    $dropOffSlotId,
                    $pickUpSlotId,
                    locale: $request->user()?->lang ?? app()->getLocale(),
                );

                $slotIds = array_values(array_unique([
                    (int) $dropOffSlotId,
                    (int) $pickUpSlotId,
                ]));

                // Atomic: lock BOTH selected slots (arrival + departure).
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
                    'reservation_kind' => ReservationKind::TIME_SLOTS,
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

                CheckoutAuditLogger::log('checkout_temp_data_created', CheckoutAuditLogger::contextFromTemp($temp));

                return $temp;
            });
        } catch (DuplicateTerminiReservationException $e) {
            $message = $e->getMessage();

            return $request->expectsJson()
                ? response()->json(['message' => $message], 422)
                : back()->withInput()->with('error', $message)->withErrors(['reservation' => $message]);
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

                $existingBillingBlock = $this->bankartBillingCountryBlockForExistingTemp(
                    $request,
                    $existingByMtid,
                    $snapshot,
                    $date,
                    ReservationKind::TIME_SLOTS,
                );
                if ($existingBillingBlock !== null) {
                    return $existingBillingBlock;
                }

                return $this->resumeOrStartBankartSession($request, $paymentService, $existingByMtid, 'checkout_after_unique_violation');
            }
            throw $e;
        }

        if ($isFree) {
            $created = $successHandler->handle($temp, ['source' => 'free_checkout'], false);

            return $this->redirectAfterFreeCheckout($request, $created, $temp->merchant_transaction_id);
        }

        $session = $this->startBankartCreateSession($paymentService, $temp, 'checkout_after_temp_created');

        if ($session->success && $session->paymentUrl) {
            return $this->redirectToPaymentUrl($request, $temp, $session->paymentUrl);
        }

        return $this->createSessionFailedResponse($request, $temp, $session, 'checkout_after_temp_created');
    }

    private function resumeOrStartBankartSession(
        CheckoutReservationRequest $request,
        PaymentService $paymentService,
        TempData $temp,
        string $stage,
    ): RedirectResponse|JsonResponse|Response {
        $redirectService = app(PendingBankartRedirectService::class);

        $storedUrl = $redirectService->storedRedirectUrl($temp);
        if ($storedUrl !== null) {
            CheckoutAuditLogger::log('checkout_existing_pending_reused_redirect', array_merge(
                CheckoutAuditLogger::contextFromTemp($temp),
                ['stage' => $stage],
            ));

            return redirect()->away($storedUrl);
        }

        $session = $this->startBankartCreateSession($paymentService, $temp, $stage);
        if ($session->success && $session->paymentUrl) {
            return $this->redirectToPaymentUrl($request, $temp, $session->paymentUrl);
        }

        if (PendingBankartRedirectService::isExistingPendingStage($stage)) {
            return $this->existingPendingSessionResponse($request, $temp, $session, $stage);
        }

        return $this->createSessionFailedResponse($request, $temp, $session, $stage);
    }

    private function existingPendingSessionResponse(
        CheckoutReservationRequest $request,
        TempData $temp,
        ?PaymentSessionResult $session,
        string $stage,
    ): RedirectResponse|JsonResponse {
        $redirectService = app(PendingBankartRedirectService::class);
        $isDuplicate = $session !== null && $redirectService->isDuplicateTransactionSession($session);

        Log::channel('payments')->info('checkout_existing_pending_session_open', array_merge(
            CheckoutAuditLogger::contextFromTemp($temp),
            array_filter([
                'stage' => $stage,
                'http_status' => $session?->httpStatus,
                'gateway_error_code' => $session?->gatewayErrorCode,
                'duplicate_transaction' => $isDuplicate,
            ], static fn ($v) => $v !== null && $v !== ''),
        ));

        $locale = $request->user()?->lang ?? app()->getLocale();
        $message = UiText::t(
            'payment',
            'payment_existing_session_open',
            $locale === 'cg'
                ? 'Prozor za plaćanje je već otvoren ili je sesija već kreirana. Nastavite postojeće plaćanje ili pokušajte ponovo za nekoliko minuta.'
                : 'The payment window is already open or a session was already created. Please continue the existing payment or try again in a few minutes.',
            $locale,
        );

        return $request->expectsJson()
            ? response()->json(['message' => $message], Response::HTTP_CONFLICT)
            : redirect()->to($this->checkoutReturnUrl($request))
                ->withInput()
                ->with('error', $message);
    }

    private function startBankartCreateSession(
        PaymentService $paymentService,
        TempData $temp,
        string $stage,
    ): PaymentSessionResult {
        CheckoutAuditLogger::log('checkout_bankart_create_session_started', array_merge(
            CheckoutAuditLogger::contextFromTemp($temp),
            ['stage' => $stage],
        ));

        $session = $paymentService->createSession($temp);

        if ($session->success && $session->paymentUrl) {
            CheckoutAuditLogger::log('checkout_bankart_create_session_success', array_merge(
                CheckoutAuditLogger::contextFromTemp($temp),
                ['stage' => $stage],
            ));
        }

        return $session;
    }

    private function redirectToPaymentUrl(
        CheckoutReservationRequest $request,
        TempData $temp,
        string $paymentUrl,
    ): RedirectResponse {
        app(PendingBankartRedirectService::class)->persistRedirectUrl($temp, $paymentUrl);
        CheckoutAuditLogger::log('checkout_redirect_created', CheckoutAuditLogger::contextFromTemp($temp));

        return redirect()->away($paymentUrl);
    }

    private function checkoutReturnUrl(CheckoutReservationRequest $request): string
    {
        if ($request->user() && $request->boolean('auth_panel_booking')) {
            return route('panel.reservations', [], false);
        }

        if ($request->resolvedReservationKind() === ReservationKind::DAILY_TICKET) {
            return route('guest.reserve', ['reservation_kind' => ReservationKind::DAILY_TICKET], false);
        }

        return route('guest.reserve', [], false);
    }

    private function createSessionFailedResponse(
        CheckoutReservationRequest $request,
        TempData $temp,
        ?PaymentSessionResult $session = null,
        string $stage = 'checkout',
    ): JsonResponse|RedirectResponse|Response {
        $locale = $request->user()?->lang ?? app()->getLocale();

        app(PaymentInitFailureService::class)->failAndRelease(
            $temp,
            $stage,
            $session?->httpStatus,
            $session?->failureReason,
        );

        app(CheckoutPaymentInitAlertService::class)->notifyIfNeeded(
            $temp,
            $stage,
            $session?->httpStatus,
            $session?->failureReason,
        );

        $failureContext = array_merge(
            CheckoutAuditLogger::contextFromTemp($temp),
            array_filter([
                'stage' => $stage,
                'http_status' => $session?->httpStatus,
                'failure_reason' => $session?->failureReason,
            ], static fn ($v) => $v !== null && $v !== ''),
        );
        CheckoutAuditLogger::log('checkout_bankart_create_session_failed', $failureContext, 'warning');
        CheckoutAuditLogger::log('checkout_failed_generic', $failureContext, 'warning');

        $billingAlert = app(BankartBillingCountryAlertService::class);
        $message = $session?->failureReason === 'invalid_billing_country'
            ? $billingAlert->userMessage($locale)
            : UiText::t(
                'payment',
                'payment_window_unavailable',
                $locale === 'cg'
                    ? 'Trenutno nije moguće otvoriti prozor za plaćanje. Molimo pokušajte ponovo za nekoliko minuta. Ako se problem ponovi, pišite na bus@kotor.me.'
                    : 'The payment window cannot be opened at the moment. Please try again in a few minutes. If the problem continues, contact bus@kotor.me.',
                $locale,
            );

        return $request->expectsJson()
            ? response()->json(['message' => $message], Response::HTTP_SERVICE_UNAVAILABLE)
            : redirect()->to($this->checkoutReturnUrl($request))
                ->withInput()
                ->with('error', $message);
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
    private function tryResumeExistingPendingTimeSlotsCheckout(
        CheckoutReservationRequest $request,
        PaymentService $paymentService,
        PaymentSuccessHandler $successHandler,
        string $date,
        int $dropOffSlotId,
        int $pickUpSlotId,
        array $snapshot,
    ): RedirectResponse|JsonResponse|Response|null {
        $existingBySlot = $this->findExistingPendingForSlot($request, $date, $dropOffSlotId, $pickUpSlotId);
        if (! $existingBySlot) {
            return null;
        }

        $exArrival = ListOfTimeSlot::query()->find($existingBySlot->drop_off_time_slot_id);
        $exDeparture = ListOfTimeSlot::query()->find($existingBySlot->pick_up_time_slot_id);
        if ($exArrival && $exDeparture && FreeReservationRules::isFreeReservation($exArrival, $exDeparture)) {
            $created = $successHandler->handle($existingBySlot, ['source' => 'free_checkout', 'context' => 'existing_pending'], false);

            return $this->redirectAfterFreeCheckout($request, $created, $existingBySlot->merchant_transaction_id);
        }

        $existingBillingBlock = $this->bankartBillingCountryBlockForExistingTemp(
            $request,
            $existingBySlot,
            $snapshot,
            $date,
            ReservationKind::TIME_SLOTS,
        );
        if ($existingBillingBlock !== null) {
            return $existingBillingBlock;
        }

        return $this->resumeOrStartBankartSession($request, $paymentService, $existingBySlot, 'checkout_existing_pending');
    }

    private function findExistingPendingForSlot(
        CheckoutReservationRequest $request,
        string $date,
        int $dropOffSlotId,
        int $pickUpSlotId,
    ): ?TempData {
        $query = TempData::query()
            ->whereDate('reservation_date', $date)
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
    /**
     * Daily ticket (guest card-only or agency card/advance): no slot capacity, no daily_parking_data, no slot duplicate check.
     */
    private function storeDailyTicketBooking(
        CheckoutReservationRequest $request,
        PaymentService $paymentService,
        PaymentSuccessHandler $successHandler,
    ): RedirectResponse|Response|JsonResponse {
        $date = $request->validated('reservation_date');
        $snapshot = $this->resolveSnapshotInput($request);
        CheckoutAuditLogger::log('checkout_started', CheckoutAuditLogger::contextFromRequest($request, $snapshot));

        $panelAuthBooking = $request->isPanelAuthBooking();
        $paymentMethod = $panelAuthBooking ? (string) ($request->validated('payment_method') ?? 'card') : 'card';

        $lowerCategoryBlock = $this->guestLowerCategoryBlockResponse(
            $request,
            $snapshot,
            $date,
            ReservationKind::DAILY_TICKET,
            false,
        );
        if ($lowerCategoryBlock !== null) {
            return $lowerCategoryBlock;
        }

        $billingCountryBlock = $this->bankartBillingCountryBlockResponse(
            $request,
            $snapshot,
            $date,
            ReservationKind::DAILY_TICKET,
            false,
            $paymentMethod,
        );
        if ($billingCountryBlock !== null) {
            return $billingCountryBlock;
        }

        if ($panelAuthBooking && $paymentMethod === 'advance') {
            if (! (bool) config('features.advance_payments')) {
                $msg = 'Avansno plaćanje trenutno nije dostupno.';

                return back()->withInput()->with('error', $msg)->withErrors(['payment_method' => $msg]);
            }

            $invoiceAmount = ReservationInvoiceAmount::snapshotForNewReservation('paid', $snapshot['vehicle_type_id'] ?? null);
            $userId = (int) $request->user()->id;

            $merchantTransactionId = $request->validated('merchant_transaction_id');
            $merchantTransactionId = is_string($merchantTransactionId) && trim($merchantTransactionId) !== ''
                ? trim($merchantTransactionId)
                : Str::uuid()->toString();

            try {
                $reservation = DB::transaction(function () use ($date, $snapshot, $invoiceAmount, $userId, $merchantTransactionId) {
                    User::query()->whereKey($userId)->lockForUpdate()->first();

                    $balance = (float) (DB::table('agency_advance_transactions')
                        ->where('agency_user_id', $userId)
                        ->selectRaw('COALESCE(SUM(amount), 0) as s')
                        ->value('s') ?? 0);
                    $need = (float) $invoiceAmount;
                    if ($balance + 0.000001 < $need) {
                        $msg = 'Raspoloživi avans nije dovoljan za ovu rezervaciju. Možete izabrati plaćanje karticom ili izvršiti avansnu uplatu.';
                        abort(422, $msg);
                    }

                    $reservation = Reservation::create([
                        'user_id' => $userId,
                        'vehicle_id' => $snapshot['vehicle_id'],
                        'merchant_transaction_id' => $merchantTransactionId,
                        'payment_method' => 'advance',
                        'reservation_kind' => ReservationKind::DAILY_TICKET,
                        'drop_off_time_slot_id' => null,
                        'pick_up_time_slot_id' => null,
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
            }

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

        $merchantTransactionId = Str::uuid()->toString();
        $retryToken = Str::uuid()->toString();

        try {
            $temp = DB::transaction(function () use ($request, $date, $merchantTransactionId, $retryToken, $snapshot) {
                $preferredLocale = $request->user()
                    ? ($request->user()->lang ?? 'en')
                    : ($request->session()->get('locale') ?: app()->getLocale());
                if (! LocaleHelper::isValid($preferredLocale)) {
                    $preferredLocale = 'en';
                }

                $invoiceSnapshot = ReservationInvoiceAmount::snapshotForNewReservation(
                    'paid',
                    $snapshot['vehicle_type_id'] ?? null
                );

                $temp = TempData::create([
                    'merchant_transaction_id' => $merchantTransactionId,
                    'retry_token' => $retryToken,
                    'user_id' => $request->user()?->id,
                    'vehicle_id' => $snapshot['vehicle_id'],
                    'reservation_kind' => ReservationKind::DAILY_TICKET,
                    'drop_off_time_slot_id' => null,
                    'pick_up_time_slot_id' => null,
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

                Log::channel('payments')->info('Payment init', [
                    'merchant_transaction_id' => $merchantTransactionId,
                    'temp_data_id' => $temp->id,
                    'user_id' => $temp->user_id,
                    'reservation_id' => null,
                    'retry_token' => $retryToken,
                    'reservation_kind' => ReservationKind::DAILY_TICKET,
                ]);

                CheckoutAuditLogger::log('checkout_temp_data_created', CheckoutAuditLogger::contextFromTemp($temp));

                return $temp;
            });
        } catch (\Illuminate\Database\UniqueConstraintViolationException $e) {
            $existingByMtid = TempData::where('merchant_transaction_id', $merchantTransactionId)
                ->where('status', TempData::STATUS_PENDING)
                ->first();
            if ($existingByMtid) {
                $existingBillingBlock = $this->bankartBillingCountryBlockForExistingTemp(
                    $request,
                    $existingByMtid,
                    $snapshot,
                    $date,
                    ReservationKind::DAILY_TICKET,
                );
                if ($existingBillingBlock !== null) {
                    return $existingBillingBlock;
                }

                return $this->resumeOrStartBankartSession($request, $paymentService, $existingByMtid, 'checkout_daily_ticket_after_unique_violation');
            }
            throw $e;
        }

        $session = $this->startBankartCreateSession($paymentService, $temp, 'checkout_daily_ticket_after_temp_created');

        if ($session->success && $session->paymentUrl) {
            return $this->redirectToPaymentUrl($request, $temp, $session->paymentUrl);
        }

        return $this->createSessionFailedResponse($request, $temp, $session, 'checkout_daily_ticket_after_temp_created');
    }

    private function duplicateConflictResponse(
        CheckoutReservationRequest $request,
        DuplicateReservationAttemptService $duplicateAttemptService,
        string $date,
        string $licensePlate,
        int $dropOffSlotId,
        int $pickUpSlotId,
    ): RedirectResponse|JsonResponse|null {
        if (! $duplicateAttemptService->existsConflict($date, $licensePlate, $dropOffSlotId, $pickUpSlotId)) {
            return null;
        }

        $locale = $request->user()?->lang ?? app()->getLocale();
        $message = $duplicateAttemptService->conflictMessage($locale);

        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->withInput()->with('error', $message)->withErrors(['reservation' => $message]);
    }

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
                'license_plate' => DuplicateReservationAttemptService::normalizeLicensePlate($vehicle->license_plate),
                'vehicle_type_id' => (int) $vehicle->vehicle_type_id,
                'email' => (string) ($request->user()->email ?? ''),
            ];
        }

        return [
            'vehicle_id' => null,
            'user_name' => (string) $request->validated('name'),
            'country' => (string) $request->validated('country'),
            'license_plate' => DuplicateReservationAttemptService::normalizeLicensePlate((string) $request->validated('license_plate')),
            'vehicle_type_id' => (int) $request->validated('vehicle_type_id'),
            'email' => (string) $request->validated('email'),
        ];
    }

    /**
     * @param  array{vehicle_id:int|null,user_name:string,country:string,license_plate:string,vehicle_type_id:int,email:string}  $snapshot
     */
    private function bankartBillingCountryBlockResponse(
        CheckoutReservationRequest $request,
        array $snapshot,
        string $reservationDate,
        string $reservationKind,
        bool $isFreeReservation,
        string $paymentMethod,
    ): RedirectResponse|JsonResponse|null {
        if ($isFreeReservation) {
            return null;
        }

        $panelAuthBooking = $request->user() !== null && $request->boolean('auth_panel_booking');
        if ($panelAuthBooking && $paymentMethod === 'advance') {
            return null;
        }

        $rawCountry = (string) ($snapshot['country'] ?? '');
        if (BankartBillingCountry::isSelectablePaymentCountry($rawCountry)) {
            return null;
        }

        $locale = $request->user()?->lang ?? app()->getLocale();
        $alert = app(BankartBillingCountryAlertService::class);
        $alert->notifyAndLog(
            $rawCountry,
            BankartBillingCountry::normalize($rawCountry),
            $request->user()?->id !== null ? (int) $request->user()->id : null,
            (string) ($snapshot['email'] ?? ''),
            $reservationKind,
            $reservationDate,
            null,
            null,
            'checkout',
            CheckoutAuditLogger::contextFromRequest($request, $snapshot),
        );

        CheckoutAuditLogger::log('checkout_billing_country_blocked', array_merge(
            CheckoutAuditLogger::contextFromRequest($request, $snapshot),
            [
                'reservation_date' => $reservationDate,
                'reservation_kind' => $reservationKind,
                'selected_country' => $rawCountry,
                'normalized_country' => BankartBillingCountry::normalize($rawCountry),
            ],
        ), 'warning');

        $message = $alert->userMessage($locale);

        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->withInput()
                ->with('error', $message)
                ->withErrors(['country' => $message]);
    }

    /**
     * @param  array{vehicle_id:int|null,user_name:string,country:string,license_plate:string,vehicle_type_id:int,email:string}  $snapshot
     */
    private function bankartBillingCountryBlockForExistingTemp(
        CheckoutReservationRequest $request,
        TempData $temp,
        array $snapshot,
        string $reservationDate,
        string $reservationKind,
    ): RedirectResponse|JsonResponse|null {
        if (BankartBillingCountry::isSelectablePaymentCountry($temp->country)) {
            return null;
        }

        if ($temp->status === TempData::STATUS_PENDING) {
            app(PaymentInitFailureService::class)->failAndRelease(
                $temp,
                'checkout_billing_country_invalid',
                null,
                'invalid_billing_country',
            );
        }

        $locale = $request->user()?->lang ?? app()->getLocale();
        $alert = app(BankartBillingCountryAlertService::class);
        $alert->notifyForTempData($temp, 'checkout_existing_pending');

        CheckoutAuditLogger::log('checkout_billing_country_blocked', array_merge(
            CheckoutAuditLogger::contextFromTemp($temp),
            [
                'reservation_date' => $reservationDate,
                'reservation_kind' => $reservationKind,
                'selected_country' => (string) ($temp->country ?? ''),
                'normalized_country' => BankartBillingCountry::normalize($temp->country),
            ],
        ), 'warning');

        $message = $alert->userMessage($locale);

        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : redirect()->to($this->checkoutReturnUrl($request))
                ->withInput()
                ->with('error', $message)
                ->withErrors(['country' => $message]);
    }

    /**
     * @param  array{vehicle_id:int|null,user_name:string,country:string,license_plate:string,vehicle_type_id:int,email:string}  $snapshot
     */
    private function guestLowerCategoryBlockResponse(
        CheckoutReservationRequest $request,
        array $snapshot,
        string $reservationDate,
        string $reservationKind,
        bool $isFreeReservation,
    ): RedirectResponse|JsonResponse|null {
        if ($request->user() !== null || $isFreeReservation) {
            return null;
        }

        $guard = app(GuestPaidLowerCategoryCheckoutGuard::class);
        $block = $guard->evaluateForGuestCheckout(
            (string) $snapshot['license_plate'],
            (int) $snapshot['vehicle_type_id'],
        );
        if ($block === null) {
            return null;
        }

        $guard->notifyBlocked(
            $block,
            (string) $snapshot['user_name'],
            (string) $snapshot['email'],
            $reservationDate,
            $reservationKind,
        );

        CheckoutAuditLogger::log('checkout_guest_lower_category_blocked', array_merge(
            CheckoutAuditLogger::contextFromRequest($request, $snapshot),
            [
                'reservation_date' => $reservationDate,
                'historical_reservation_id' => $block['historical_reservation_id'],
                'submitted_vehicle_type_id' => $block['submitted_vehicle_type_id'],
            ],
        ));

        $message = $block['plain_message'];

        return $request->expectsJson()
            ? response()->json(['message' => $message], 422)
            : back()->withInput()
                ->with('guest_lower_category_block', $block)
                ->with('error', $message)
                ->withErrors(['vehicle_type_id' => $message]);
    }
}
