<?php

namespace App\Services\Pricing;

use App\Models\Admin;
use App\Models\PriceChangeSchedule;
use App\Models\PriceChangeScheduleLine;
use App\Models\VehicleType;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin create / edit / cancel of the single future price change.
 */
final class PriceChangeAdmin
{
    public function __construct(
        private readonly VehicleTariff $tariff,
    ) {}

    /**
     * @param  array{effective_on: string, prices: array<int|string, mixed>}  $input
     */
    public function create(Admin $admin, array $input): PriceChangeSchedule
    {
        return $this->persist(null, $admin, $input);
    }

    /**
     * @param  array{effective_on: string, prices: array<int|string, mixed>}  $input
     */
    public function update(Admin $admin, array $input): PriceChangeSchedule
    {
        return $this->persist($this->scheduledOrFail(), $admin, $input);
    }

    public function cancel(Admin $admin): void
    {
        DB::transaction(function () use ($admin): void {
            $schedule = PriceChangeSchedule::query()
                ->where('status', PriceChangeSchedule::STATUS_SCHEDULED)
                ->lockForUpdate()
                ->first();

            if ($schedule === null) {
                throw ValidationException::withMessages([
                    'price_change' => 'Nema zakazane promjene cijena.',
                ]);
            }

            $schedule->status = PriceChangeSchedule::STATUS_CANCELLED;
            $schedule->pending_slot = null;
            $schedule->cancelled_at = $this->tariff->now();
            $schedule->updated_by_admin_id = $admin->id;
            $schedule->save();
        });
    }

    /**
     * @param  array{effective_on: string, prices: array<int|string, mixed>}  $input
     */
    private function persist(?PriceChangeSchedule $existing, Admin $admin, array $input): PriceChangeSchedule
    {
        $effectiveOn = $this->futureDate((string) ($input['effective_on'] ?? ''));
        $submittedPrices = is_array($input['prices'] ?? null) ? $input['prices'] : [];

        try {
            return $this->persistLocked($existing, $admin, $effectiveOn, $submittedPrices);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([
                'price_change' => 'Već postoji zakazana promjena cijena. Izmijenite ili uklonite nju.',
            ]);
        }
    }

    /**
     * @param  array<int|string, mixed>  $submittedPrices
     */
    private function persistLocked(?PriceChangeSchedule $existing, Admin $admin, string $effectiveOn, array $submittedPrices): PriceChangeSchedule
    {
        return DB::transaction(function () use ($existing, $admin, $effectiveOn, $submittedPrices): PriceChangeSchedule {
            $lines = $this->changedLines($submittedPrices);
            $lockedExisting = null;
            if ($existing !== null) {
                $lockedExisting = PriceChangeSchedule::query()
                    ->whereKey($existing->id)
                    ->lockForUpdate()
                    ->first();
                if ($lockedExisting === null || ! $lockedExisting->isScheduled()) {
                    throw ValidationException::withMessages([
                        'price_change' => 'Zakazana promjena više nije dostupna za izmjenu.',
                    ]);
                }
            }

            $otherPending = PriceChangeSchedule::query()
                ->where('status', PriceChangeSchedule::STATUS_SCHEDULED)
                ->when($lockedExisting !== null, fn ($query) => $query->whereKeyNot($lockedExisting->id))
                ->lockForUpdate()
                ->exists();
            if ($otherPending) {
                throw ValidationException::withMessages([
                    'price_change' => 'Već postoji zakazana promjena cijena. Izmijenite ili uklonite nju.',
                ]);
            }

            if ($lockedExisting === null) {
                $schedule = PriceChangeSchedule::query()->create([
                    'effective_on' => $effectiveOn,
                    'status' => PriceChangeSchedule::STATUS_SCHEDULED,
                    'pending_slot' => PriceChangeSchedule::PENDING_SLOT,
                    'created_by_admin_id' => $admin->id,
                    'updated_by_admin_id' => $admin->id,
                ]);
            } else {
                $lockedExisting->lines()->delete();
                $lockedExisting->effective_on = $effectiveOn;
                $lockedExisting->pending_slot = PriceChangeSchedule::PENDING_SLOT;
                $lockedExisting->updated_by_admin_id = $admin->id;
                $lockedExisting->save();
                $schedule = $lockedExisting;
            }

            foreach ($lines as $line) {
                PriceChangeScheduleLine::query()->create([
                    'price_change_schedule_id' => $schedule->id,
                    'vehicle_type_id' => $line['vehicle_type_id'],
                    'price_before' => $line['price_before'],
                    'price_after' => $line['price_after'],
                ]);
            }

            return $schedule->load('lines');
        });
    }

    private function scheduledOrFail(): PriceChangeSchedule
    {
        $schedule = $this->tariff->pendingSchedule();
        if ($schedule === null) {
            throw ValidationException::withMessages([
                'price_change' => 'Nema zakazane promjene cijena.',
            ]);
        }

        return $schedule;
    }

    private function futureDate(string $effectiveOn): string
    {
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', $effectiveOn)) {
            throw ValidationException::withMessages([
                'effective_on' => 'Unesite datum važenja.',
            ]);
        }

        [$year, $month, $day] = array_map('intval', explode('-', $effectiveOn));
        if (! checkdate($month, $day, $year)) {
            throw ValidationException::withMessages([
                'effective_on' => 'Unesite ispravan datum važenja.',
            ]);
        }

        $today = $this->tariff->now()->toDateString();
        if ($effectiveOn <= $today) {
            throw ValidationException::withMessages([
                'effective_on' => 'Datum važenja mora biti u budućnosti. Nove cijene počinju u 00:00 tog dana (Europe/Podgorica).',
            ]);
        }

        return $effectiveOn;
    }

    /**
     * @param  array<int|string, mixed>  $prices
     * @return list<array{vehicle_type_id: int, price_before: string, price_after: string}>
     */
    private function changedLines(array $prices): array
    {
        $this->tariff->applyDue();
        $types = VehicleType::query()->orderBy('id')->get()->keyBy('id');
        $lines = [];
        $errors = [];

        foreach ($prices as $id => $raw) {
            if ($raw === null || (is_string($raw) && trim($raw) === '')) {
                continue;
            }
            if (! is_scalar($raw)) {
                $errors['prices.'.$id] = 'Unesite ispravnu cijenu.';

                continue;
            }

            $typeId = (int) $id;
            if (! $types->has($typeId)) {
                $errors['prices.'.$id] = 'Nepoznata kategorija vozila.';

                continue;
            }

            $parsed = VehicleTariff::parseMoney((string) $raw);
            if ($parsed === null) {
                $errors['prices.'.$id] = 'Cijena mora biti iznos od 0.01 do 99999999.99 EUR, sa najviše dvije decimale.';

                continue;
            }

            $current = VehicleTariff::money($types[$typeId]->price);
            if (VehicleTariff::cmp($parsed, $current) === 0) {
                continue;
            }

            $lines[] = [
                'vehicle_type_id' => $typeId,
                'price_before' => $current,
                'price_after' => $parsed,
            ];
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        if ($lines === []) {
            throw ValidationException::withMessages([
                'prices' => 'Najmanje jedna kategorija mora imati novu cijenu.',
            ]);
        }

        return $lines;
    }
}
