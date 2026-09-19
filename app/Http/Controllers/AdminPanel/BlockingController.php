<?php

namespace App\Http\Controllers\AdminPanel;

use App\Http\Controllers\Controller;
use App\Jobs\SendFreeReservationConfirmationJob;
use App\Jobs\SendInvoiceEmailJob;
use App\Models\BlockZoneWorklist;
use App\Models\DailyParkingData;
use App\Models\Reservation;
use App\Services\AdminPanel\Blocking\BlockReservationAdjustmentValidator;
use App\Services\AdminPanel\Blocking\BlockZoneWorklistService;
use App\Services\AdminPanel\Blocking\BlockingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class BlockingController extends Controller
{
    public function index(Request $request, BlockingService $blocking): View
    {
        $selectedDate = (string) $request->query('date', now()->toDateString());
        $slots = $blocking->allSlots();

        $dailyBySlotId = DailyParkingData::query()
            ->whereDate('date', $selectedDate)
            ->get()
            ->keyBy('time_slot_id');

        $worklist = BlockZoneWorklist::query()
            ->orderByDesc('created_at')
            ->get();

        return view('admin-panel.blocking.index', [
            'navActive' => 'blocking',
            'selectedDate' => $selectedDate,
            'slots' => $slots,
            'dailyBySlotId' => $dailyBySlotId,
            'blockedDays' => $blocking->blockedDaySummaries(),
            'worklist' => $worklist,
        ]);
    }

    public function applyBlock(Request $request, BlockingService $blocking): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'block_whole_day' => ['sometimes', 'boolean'],
            'slot_ids' => ['sometimes', 'array'],
            'slot_ids.*' => ['integer'],
        ]);

        $date = (string) $data['date'];
        $blockWholeDay = (bool) ($data['block_whole_day'] ?? false);
        $slotIds = array_map('intval', (array) ($data['slot_ids'] ?? []));

        if ($blockWholeDay) {
            $slotIds = $blocking->allSlots()->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        $summary = $blocking->applyBlock($date, $slotIds);
        $blocked = (int) ($summary['blocked_slots'] ?? 0);
        $worklist = (int) ($summary['worklist_touched'] ?? 0);

        $status = 'Blokiranje je primijenjeno. Blokirano termina: '.$blocked.'.';
        if ($worklist > 0) {
            $status .= ' Stavki za prilagođavanje (postojeće rezervacije / plaćanja u toku): '.$worklist.'.';
        }

        return $this->redirectFresh('panel_admin.blocking', ['date' => $date])
            ->with('status', $status);
    }

    public function day(string $date, BlockingService $blocking): View
    {
        $slots = $blocking->allSlots();
        $dailyBySlotId = DailyParkingData::query()
            ->whereDate('date', $date)
            ->get()
            ->keyBy('time_slot_id');

        return view('admin-panel.blocking.day', [
            'navActive' => 'blocking',
            'date' => $date,
            'slots' => $slots,
            'dailyBySlotId' => $dailyBySlotId,
        ]);
    }

    public function applyUnblock(Request $request, BlockingService $blocking): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'unblock_all' => ['sometimes', 'boolean'],
            'slot_ids' => ['sometimes', 'array'],
            'slot_ids.*' => ['integer'],
        ]);
        $date = (string) $data['date'];
        $unblockAll = (bool) ($data['unblock_all'] ?? false);
        $slotIds = array_map('intval', (array) ($data['slot_ids'] ?? []));
        if ($unblockAll) {
            $slotIds = $blocking->allSlots()->pluck('id')->map(fn ($v) => (int) $v)->all();
        }

        $blocking->applyUnblock($date, $slotIds);

        return $this->redirectFresh('panel_admin.blocking.day', ['date' => $date])
            ->with('status', 'Deblokiranje je primenjeno.');
    }

    public function adjust(BlockZoneWorklist $row, BlockingService $blocking): View
    {
        $reservation = $row->reservation_id ? Reservation::find($row->reservation_id) : null;
        $prefilterDates = $blocking->datesEligibleForAdjustmentPrefilter();

        return view('admin-panel.blocking.adjust', [
            'navActive' => 'blocking',
            'row' => $row,
            'reservation' => $reservation,
            'slots' => $blocking->allSlots(),
            'prefilterDates' => $prefilterDates,
        ]);
    }

    public function applyAdjust(
        Request $request,
        BlockZoneWorklist $row,
        BlockingService $blocking,
        BlockReservationAdjustmentValidator $finalValidator,
        BlockZoneWorklistService $worklistService,
    ): RedirectResponse {
        if ($row->status !== BlockZoneWorklist::STATUS_READY_TO_ADJUST || ! $row->reservation_id) {
            return back()->with('error', 'Ova stavka nije spremna za prilagođavanje (pending payment).');
        }

        $data = $request->validate([
            'new_date' => ['required', 'date'],
            'new_drop_off' => ['required', 'integer'],
            'new_pick_up' => ['required', 'integer'],
        ]);

        $newDate = (string) $data['new_date'];
        $newDrop = (int) $data['new_drop_off'];
        $newPick = (int) $data['new_pick_up'];

        if ($newDate < now()->toDateString()) {
            return back()->with('error', 'Novi datum ne sme biti u prošlosti.');
        }

        $prefilter = $blocking->datesEligibleForAdjustmentPrefilter();
        if ($prefilter !== [] && ! in_array($newDate, $prefilter, true)) {
            return back()->with('error', 'Izabrani datum nije u dozvoljenom prefiltetu kalendara; izaberite drugi dan.');
        }

        $reservationId = (int) $row->reservation_id;
        $oldDate = (string) $row->old_date->toDateString();
        $oldDrop = (int) $row->old_drop_off;
        $oldPick = (int) $row->old_pick_up;

        try {
            DB::transaction(function () use (
                $row,
                $finalValidator,
                $worklistService,
                $reservationId,
                $oldDate,
                $oldDrop,
                $oldPick,
                $newDate,
                $newDrop,
                $newPick,
            ): void {
                $lockedRow = BlockZoneWorklist::query()->whereKey($row->id)->lockForUpdate()->first();
                if ($lockedRow === null) {
                    throw new \RuntimeException('Stavka više nije dostupna.');
                }
                if ($lockedRow->status !== BlockZoneWorklist::STATUS_READY_TO_ADJUST || $lockedRow->reservation_id === null) {
                    throw new \RuntimeException('Stavka nije spremna za prilagođavanje.');
                }

                /** @var Reservation|null $r */
                $r = Reservation::query()->whereKey($reservationId)->lockForUpdate()->first();
                if (! $r) {
                    throw new \RuntimeException('Rezervacija nije pronađena.');
                }

                $tuples = [
                    [$oldDate, $oldDrop],
                    [$oldDate, $oldPick],
                    [$newDate, $newDrop],
                    [$newDate, $newPick],
                ];
                $uniqueKeys = [];
                foreach ($tuples as [$d, $sid]) {
                    $uniqueKeys[$d.'|'.$sid] = [$d, $sid];
                }
                $sorted = array_values($uniqueKeys);
                usort($sorted, function (array $a, array $b): int {
                    if ($a[0] !== $b[0]) {
                        return $a[0] <=> $b[0];
                    }

                    return $a[1] <=> $b[1];
                });

                /** @var array<string, DailyParkingData> $dailyByKey */
                $dailyByKey = [];
                foreach ($sorted as [$d, $sid]) {
                    $key = $d.'|'.$sid;
                    $m = DailyParkingData::query()
                        ->whereDate('date', $d)
                        ->where('time_slot_id', $sid)
                        ->lockForUpdate()
                        ->first();
                    if ($m === null) {
                        throw new \RuntimeException('Nedostaje daily_parking_data za datum/termin.');
                    }
                    $dailyByKey[$key] = $m;
                }

                $finalValidator->assertValidAfterLock(
                    $oldDate,
                    $newDate,
                    $oldDrop,
                    $oldPick,
                    $newDrop,
                    $newPick,
                    $dailyByKey,
                );

                $get = fn (string $date, int $slotId): DailyParkingData => $dailyByKey[$date.'|'.$slotId];

                $uOld = array_values(array_unique([$oldDrop, $oldPick]));
                $uNew = array_values(array_unique([$newDrop, $newPick]));

                if ($oldDate === $newDate) {
                    $ids = array_values(array_unique(array_merge($uOld, $uNew)));
                    foreach ($ids as $sid) {
                        $delta = (in_array($sid, $uNew, true) ? 1 : 0) - (in_array($sid, $uOld, true) ? 1 : 0);
                        if ($delta === 1) {
                            $get($oldDate, $sid)->increment('reserved');
                        } elseif ($delta === -1) {
                            $get($oldDate, $sid)->decrement('reserved');
                        }
                    }
                } else {
                    foreach ($uOld as $sid) {
                        $get($oldDate, $sid)->decrement('reserved');
                    }
                    foreach ($uNew as $sid) {
                        $get($newDate, $sid)->increment('reserved');
                    }
                }

                $r->update([
                    'reservation_date' => $newDate,
                    'drop_off_time_slot_id' => $newDrop,
                    'pick_up_time_slot_id' => $newPick,
                    'invoice_sent_at' => null,
                    'email_sent' => Reservation::EMAIL_NOT_SENT,
                ]);

                $targetToBlock = [];
                if ($lockedRow->affected_drop_off) {
                    $targetToBlock[] = $oldDrop;
                }
                if ($lockedRow->affected_pick_up) {
                    $targetToBlock[] = $oldPick;
                }
                $targetToBlock = array_values(array_unique($targetToBlock));
                foreach ($targetToBlock as $sid) {
                    $d = $get($oldDate, $sid);
                    $d->refresh();
                    if ((int) $d->reserved > 0 || (int) $d->pending > 0) {
                        continue;
                    }
                    $d->is_blocked = true;
                    $d->save();
                }

                // Current membership: keep/refresh if still on a blocked slot; remove if fully clear.
                $worklistService->reconcileForReservation($r->fresh() ?? $r);

                Log::channel('payments')->info('block_zone_reservation_adjusted', [
                    'reservation_id' => $r->id,
                    'merchant_transaction_id' => $r->merchant_transaction_id,
                    'old_date' => $oldDate,
                    'new_date' => $newDate,
                    'old_drop_off' => $oldDrop,
                    'old_pick_up' => $oldPick,
                    'new_drop_off' => $newDrop,
                    'new_pick_up' => $newPick,
                ]);

                if ($r->status === 'free') {
                    SendFreeReservationConfirmationJob::dispatch($r->id);
                } else {
                    $isFiscal = $r->fiscal_jir !== null;
                    SendInvoiceEmailJob::dispatch($r->id, $isFiscal);
                }
            });
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return $this->redirectFresh('panel_admin.blocking', [])
            ->with('status', 'Rezervacija je prilagođena i dokument je ponovo u redu za slanje.');
    }

    /**
     * @param  array<string, mixed>  $params
     */
    private function redirectFresh(string $route, array $params): RedirectResponse
    {
        $params['_fresh'] = (string) time();

        return redirect()->to(route($route, $params, false));
    }
}
