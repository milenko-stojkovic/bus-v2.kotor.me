<?php

namespace App\Services\AdminPanel\Agency;

use App\Models\Reservation;
use App\Models\User;
use App\Models\Vehicle;
use App\Support\AgencyHeuristicConfidence;
use App\Support\MontenegroLicensePlate;
use App\Support\ReservationKind;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

final class AgencyV1HistoricalEstimateService
{
    /**
     * @return array<string, mixed>
     */
    public function compute(User $agency, string $tableSort = 'confidence'): array
    {
        $ttl = (int) config('agency_statistics.heuristic_cache_ttl', 3600);
        $cacheKey = 'agency_v1_hist:'.$agency->id.':'.config('agency_statistics.v1_cutover_at');

        if ($ttl <= 0) {
            return $this->buildEstimate($agency, $tableSort);
        }

        /** @var array<string, mixed> $cached */
        $cached = Cache::remember($cacheKey, $ttl, fn () => $this->buildEstimate($agency, 'confidence'));

        if ($tableSort === 'confidence') {
            return $cached;
        }

        $copy = $cached;
        $copy['linked_reservations'] = $this->sortMatches(
            collect($cached['linked_reservations'] ?? []),
            $tableSort,
        )->values()->all();

        return $copy;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildEstimate(User $agency, string $tableSort): array
    {
        $context = $this->buildAgencyContext($agency);
        $candidates = $this->fetchCandidateReservations($agency, $context);
        $matches = $candidates
            ->map(fn (Reservation $r) => $this->scoreReservation($r, $context))
            ->filter(fn (?array $m) => $m !== null)
            ->values();

        $sorted = $this->sortMatches($matches, $tableSort);

        $high = $matches->where('confidence', AgencyHeuristicConfidence::HIGH)->count();
        $medium = $matches->where('confidence', AgencyHeuristicConfidence::MEDIUM)->count();
        $low = $matches->where('confidence', AgencyHeuristicConfidence::LOW)->count();

        $paid = $matches->where('status', 'paid');
        $free = $matches->where('status', 'free');

        $dates = $matches
            ->pluck('reservation_date')
            ->filter()
            ->sort()
            ->values();

        return [
            'linked_total' => $matches->count(),
            'high_confidence' => $high,
            'medium_confidence' => $medium,
            'low_confidence' => $low,
            'estimated_paid' => $paid->count(),
            'estimated_free' => $free->count(),
            'estimated_revenue' => (float) $paid->sum(fn (array $m) => (float) ($m['amount'] ?? 0)),
            'estimated_first_reservation' => $dates->first(),
            'estimated_last_reservation' => $dates->last(),
            'linked_reservations' => $sorted->values()->all(),
        ];
    }

    /**
     * @return array{
     *     agency_email: string,
     *     agency_domain: ?string,
     *     agency_country: string,
     *     agency_name: string,
     *     active_plates: array<string, true>,
     *     repeated_v2_plates: array<string, true>,
     *     plate_lookup_values: list<string>,
     * }
     */
    private function buildAgencyContext(User $agency): array
    {
        $agencyEmail = strtolower(trim((string) $agency->email));
        $rawDomain = $this->emailDomain($agencyEmail);
        $agencyDomain = ($rawDomain !== null && ! $this->isPublicEmailDomain($rawDomain)) ? $rawDomain : null;

        $activePlates = [];
        $plateLookup = [];

        $vehicles = Vehicle::query()
            ->where('user_id', $agency->id)
            ->where('status', Vehicle::STATUS_ACTIVE)
            ->pluck('license_plate');

        foreach ($vehicles as $plate) {
            $normalized = MontenegroLicensePlate::normalizeAscii((string) $plate);
            if ($normalized === '') {
                continue;
            }
            $activePlates[$normalized] = true;
            $plateLookup[] = (string) $plate;
            $plateLookup[] = $normalized;
        }

        $repeatedV2Plates = [];
        $repeatedRows = Reservation::query()
            ->select('license_plate', DB::raw('COUNT(*) as plate_count'))
            ->where('user_id', $agency->id)
            ->whereNotNull('license_plate')
            ->where('license_plate', '!=', '')
            ->groupBy('license_plate')
            ->having('plate_count', '>=', 2)
            ->pluck('license_plate');

        foreach ($repeatedRows as $plate) {
            $normalized = MontenegroLicensePlate::normalizeAscii((string) $plate);
            if ($normalized === '') {
                continue;
            }
            $repeatedV2Plates[$normalized] = true;
            $plateLookup[] = (string) $plate;
            $plateLookup[] = $normalized;
        }

        $plateLookup = array_values(array_unique(array_filter($plateLookup)));

        return [
            'agency_email' => $agencyEmail,
            'agency_domain' => $agencyDomain,
            'agency_country' => trim((string) ($agency->country ?? '')),
            'agency_name' => trim((string) $agency->name),
            'active_plates' => $activePlates,
            'repeated_v2_plates' => $repeatedV2Plates,
            'plate_lookup_values' => $plateLookup,
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return Collection<int, Reservation>
     */
    private function fetchCandidateReservations(User $agency, array $context): Collection
    {
        $cutover = Carbon::parse((string) config('agency_statistics.v1_cutover_at', '2026-06-19 00:00:00'));

        $query = Reservation::query()
            ->whereNull('user_id')
            ->where('created_at', '<', $cutover)
            ->select([
                'id',
                'reservation_date',
                'license_plate',
                'email',
                'country',
                'user_name',
                'status',
                'invoice_amount',
                'reservation_kind',
            ]);

        $agencyEmail = (string) $context['agency_email'];
        $agencyDomain = $context['agency_domain'];
        $plateValues = $context['plate_lookup_values'];

        $query->where(function ($q) use ($agencyEmail, $agencyDomain, $plateValues): void {
            if ($agencyEmail !== '') {
                $q->whereRaw('LOWER(email) = ?', [$agencyEmail]);
            }

            // Domain prefilter only for non-public business domains (avoid massive false positives like gmail.com).
            if (is_string($agencyDomain) && $agencyDomain !== '') {
                $q->orWhereRaw('LOWER(email) LIKE ?', ['%@'.$agencyDomain]);
            }

            if ($plateValues !== []) {
                $q->orWhereIn('license_plate', $plateValues);
            }
        });

        return $query->get();
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>|null
     */
    private function scoreReservation(Reservation $reservation, array $context): ?array
    {
        $reasons = [];

        $email = strtolower(trim((string) $reservation->email));
        $agencyEmail = (string) $context['agency_email'];

        if ($agencyEmail !== '' && $email === $agencyEmail) {
            $reasons[] = ['level' => AgencyHeuristicConfidence::HIGH, 'reason' => 'Email match'];
        } elseif ($this->emailDomain($email) === $context['agency_domain']
            && is_string($context['agency_domain'])
            && $context['agency_domain'] !== '') {
            $reasons[] = ['level' => AgencyHeuristicConfidence::MEDIUM, 'reason' => 'Email domain'];
        }

        $plateNorm = MontenegroLicensePlate::normalizeAscii((string) $reservation->license_plate);
        if ($plateNorm !== '' && isset($context['active_plates'][$plateNorm])) {
            $reasons[] = ['level' => AgencyHeuristicConfidence::HIGH, 'reason' => 'Known vehicle'];
        } elseif ($plateNorm !== '' && isset($context['repeated_v2_plates'][$plateNorm])) {
            $reasons[] = ['level' => AgencyHeuristicConfidence::MEDIUM, 'reason' => 'Repeated plate in V2'];
        }

        $agencyName = (string) $context['agency_name'];
        $resName = trim((string) $reservation->user_name);
        if ($agencyName !== '' && $resName !== '') {
            $percent = 0.0;
            similar_text(mb_strtolower($agencyName), mb_strtolower($resName), $percent);
            if ($percent >= (float) config('agency_statistics.name_similarity_threshold', 80)) {
                $reasons[] = ['level' => AgencyHeuristicConfidence::LOW, 'reason' => 'Name similarity'];
            }
        }

        if ($reasons === []) {
            return null;
        }

        $agencyCountry = (string) ($context['agency_country'] ?? '');
        $reservationCountry = trim((string) ($reservation->country ?? ''));
        [$confidence, $matchingReason] = $this->resolveConfidence($reasons, $agencyCountry, $reservationCountry);

        return [
            'id' => (int) $reservation->id,
            'reservation_date' => $reservation->reservation_date?->format('Y-m-d'),
            'license_plate' => (string) $reservation->license_plate,
            'email' => (string) $reservation->email,
            'country' => (string) $reservation->country,
            'reservation_kind' => (string) $reservation->reservation_kind,
            'reservation_kind_label' => $this->kindLabel((string) $reservation->reservation_kind),
            'status' => (string) $reservation->status,
            'amount' => $reservation->status === 'paid' ? (float) ($reservation->invoice_amount ?? 0) : 0.0,
            'confidence' => $confidence,
            'confidence_label' => AgencyHeuristicConfidence::label($confidence),
            'matching_reason' => $matchingReason,
            'confidence_rank' => AgencyHeuristicConfidence::rank($confidence),
        ];
    }

    /**
     * @param  list<array{level: string, reason: string}>  $reasons
     * @param  string  $agencyCountry
     * @param  string  $reservationCountry
     * @return array{0: string, 1: string}
     */
    private function resolveConfidence(array $reasons, string $agencyCountry, string $reservationCountry): array
    {
        if (count($reasons) >= 2) {
            // Country sanity check: for medium/low-only multi-signal matches, do not upgrade
            // confidence when agency country exists and differs. Exact email / exact plate remain HIGH.
            $hasHigh = collect($reasons)->contains(fn (array $r) => $r['level'] === AgencyHeuristicConfidence::HIGH);
            $countryMismatch = $agencyCountry !== '' && $reservationCountry !== '' && $agencyCountry !== $reservationCountry;

            if (! $hasHigh && $countryMismatch) {
                $max = collect($reasons)->contains(fn (array $r) => $r['level'] === AgencyHeuristicConfidence::MEDIUM)
                    ? AgencyHeuristicConfidence::MEDIUM
                    : AgencyHeuristicConfidence::LOW;

                return [$max, 'Multiple signals'];
            }

            return [AgencyHeuristicConfidence::HIGH, 'Multiple signals'];
        }

        $only = $reasons[0];

        return [$only['level'], $only['reason']];
    }

    private function kindLabel(string $kind): string
    {
        return match ($kind) {
            ReservationKind::DAILY_TICKET => 'Dnevna naknada',
            default => 'Termini',
        };
    }

    private function emailDomain(?string $email): ?string
    {
        if ($email === null || $email === '' || ! str_contains($email, '@')) {
            return null;
        }

        $domain = strtolower(substr(strrchr($email, '@'), 1));

        return $domain !== '' ? $domain : null;
    }

    private function isPublicEmailDomain(string $domain): bool
    {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            return false;
        }

        /** @var array<int, string> $public */
        $public = (array) config('agency_statistics.public_email_domains', []);
        $public = array_map(static fn ($d) => strtolower(trim((string) $d)), $public);

        return in_array($domain, $public, true);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $matches
     * @return Collection<int, array<string, mixed>>
     */
    private function sortMatches(Collection $matches, string $sort): Collection
    {
        if ($sort === 'date') {
            return $matches->sortByDesc('reservation_date')->values();
        }

        return $matches
            ->sort(function (array $a, array $b): int {
                $rankCmp = ($b['confidence_rank'] ?? 0) <=> ($a['confidence_rank'] ?? 0);
                if ($rankCmp !== 0) {
                    return $rankCmp;
                }

                return strcmp((string) ($b['reservation_date'] ?? ''), (string) ($a['reservation_date'] ?? ''));
            })
            ->values();
    }
}
