<?php

namespace App\Services\AdminPanel\Reservation;

use App\Models\Reservation;
use App\Support\ReservationKind;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

final class AdminReservationSearchService
{
    public function __construct(
        private AdminReservationSearchHeuristic $heuristic,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function search(array $filters, int $perPage = 50): LengthAwarePaginator
    {
        $q = Reservation::query()
            ->with(['pickUpTimeSlot', 'dropOffTimeSlot', 'vehicleType.translations', 'user'])
            ->orderByDesc('reservation_date')
            ->orderByDesc('id');

        $this->applyFilters($q, $filters);

        return $q->paginate($perPage)->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function applyFilters(Builder $q, array $filters): void
    {
        if (! empty($filters['merchant_transaction_id'])) {
            $mtid = trim((string) $filters['merchant_transaction_id']);
            if ($mtid !== '') {
                $q->where('merchant_transaction_id', $mtid);
            }
        }

        if (! empty($filters['user_id'])) {
            $q->where('user_id', (int) $filters['user_id']);
        }

        if (! empty($filters['date_interval']) && $filters['date_interval'] === true) {
            if (! empty($filters['date_from']) && ! empty($filters['date_to'])) {
                $q->whereBetween('reservation_date', [
                    (string) $filters['date_from'],
                    (string) $filters['date_to'],
                ]);
            }
        } elseif (! empty($filters['date_single'])) {
            $q->whereDate('reservation_date', (string) $filters['date_single']);
        }

        if (! empty($filters['name_patterns']) && is_array($filters['name_patterns'])) {
            $patterns = $filters['name_patterns'];
            $q->where(function (Builder $qq) use ($patterns): void {
                foreach ($patterns as $p) {
                    $qq->orWhere('user_name', 'like', $p);
                }
            });
        }

        if (! empty($filters['email_patterns']) && is_array($filters['email_patterns'])) {
            $patterns = $filters['email_patterns'];
            $q->where(function (Builder $qq) use ($patterns): void {
                foreach ($patterns as $p) {
                    $qq->orWhere('email', 'like', $p);
                }
            });
        }

        if (! empty($filters['vehicle_type_id'])) {
            $q->where('vehicle_type_id', (int) $filters['vehicle_type_id']);
        }

        if (! empty($filters['license_plate'])) {
            $plate = strtoupper(preg_replace('/\s+/', '', (string) $filters['license_plate']) ?? '');
            if ($plate !== '') {
                $q->whereRaw("REPLACE(UPPER(license_plate), ' ', '') LIKE ?", ['%'.$plate.'%']);
            }
        }

        if (! empty($filters['country'])) {
            $q->where('country', (string) $filters['country']);
        }

        if (! empty($filters['status']) && in_array($filters['status'], ['paid', 'free'], true)) {
            $q->where('status', $filters['status']);
        }

        if (! empty($filters['reservation_kind']) && in_array($filters['reservation_kind'], ReservationKind::ALL, true)) {
            $q->where('reservation_kind', $filters['reservation_kind']);
        }
    }

    /**
     * @return array{name_patterns?:list<string>,email_patterns?:list<string>}
     */
    public function buildHeuristicPatterns(?string $name, ?string $email): array
    {
        $out = [];
        if ($name !== null && trim($name) !== '') {
            $p = $this->heuristic->nameLikePatterns($name);
            if ($p !== []) {
                $out['name_patterns'] = $p;
            }
        }
        if ($email !== null && trim($email) !== '') {
            $p = $this->heuristic->emailLikePatterns($email);
            if ($p !== []) {
                $out['email_patterns'] = $p;
            }
        }

        return $out;
    }
}
