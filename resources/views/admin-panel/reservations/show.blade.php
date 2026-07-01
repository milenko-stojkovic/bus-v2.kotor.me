@php
    use App\Models\Reservation;
    use App\Support\ReservationKind;
    /** @var Reservation $reservation */
    $kindLabel = $reservation->isDailyTicket()
        ? 'Dnevna naknada'
        : ($reservation->isTimeSlots() ? 'Termini' : (string) ($reservation->reservation_kind ?? '—'));

    $emailSentLabel = match ((int) $reservation->email_sent) {
        Reservation::EMAIL_SENT => 'Poslato',
        Reservation::EMAIL_SENDING => 'U slanju',
        default => 'Nije poslato',
    };
@endphp

<x-admin-panel-layout :page-title="$pageTitle ?? 'Rezervacija'" nav-active="reservations">
    <div class="space-y-6">
        <header class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-2xl font-semibold text-gray-900">Rezervacija #{{ $reservation->id }}</h1>
                <p class="text-sm text-gray-600 mt-1">Samo pregled — bez izmjena na ovoj stranici.</p>
            </div>
            <div class="flex flex-wrap gap-2">
                <a href="{{ route('panel_admin.reservations', [], false) }}"
                   class="text-sm text-red-700 underline font-medium">Nazad na pretragu</a>
                <a href="{{ route('panel_admin.reservations.pdf', $reservation, false) }}" target="_blank" rel="noopener"
                   class="inline-flex justify-center items-center px-3 py-2 border border-red-200 rounded-md text-xs font-semibold text-gray-700 uppercase tracking-widest hover:bg-red-50">
                    PDF
                </a>
            </div>
        </header>

        <section class="bg-white shadow rounded-lg p-4 sm:p-6 border border-red-100">
            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-6 gap-y-4 text-sm">
                <div>
                    <dt class="text-gray-500">ID rezervacije</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">#{{ $reservation->id }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Status</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">{{ $reservation->status }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Tip rezervacije</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">{{ $kindLabel }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Datum</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">{{ $reservation->reservation_date->format('d.m.Y.') }}</dd>
                </div>
                @if ($reservation->isDailyTicket())
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Vrsta / važenje</dt>
                        <dd class="font-medium text-gray-900 mt-0.5">
                            @include('partials.reservation-slot-display', ['reservation' => $reservation, 'slot' => null, 'locale' => 'cg'])
                            — {{ $reservation->reservation_date->format('d.m.Y.') }}
                        </dd>
                    </div>
                @else
                    <div>
                        <dt class="text-gray-500">Dolazak (drop-off)</dt>
                        <dd class="font-medium text-gray-900 mt-0.5">
                            @include('partials.reservation-slot-display', ['reservation' => $reservation, 'slot' => $reservation->dropOffTimeSlot, 'locale' => 'cg'])
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Odlazak (pick-up)</dt>
                        <dd class="font-medium text-gray-900 mt-0.5">
                            @include('partials.reservation-slot-display', ['reservation' => $reservation, 'slot' => $reservation->pickUpTimeSlot, 'locale' => 'cg'])
                        </dd>
                    </div>
                @endif
                <div>
                    <dt class="text-gray-500">Iznos</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">
                        @if ($reservation->status === 'paid')
                            {{ number_format((float) $reservation->invoice_amount, 2, '.', '') }} EUR
                        @else
                            —
                        @endif
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500">MTID</dt>
                    <dd class="font-medium text-gray-900 mt-0.5 break-all">{{ $reservation->merchant_transaction_id ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Tablica</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">{{ $reservation->license_plate }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Tip vozila</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">{{ $reservation->vehicleType?->formatLabel('cg', 'EUR') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Država</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">{{ $reservation->country ?? '—' }}</dd>
                </div>
                @if ($reservation->isGuest())
                    <div>
                        <dt class="text-gray-500">Tip korisnika</dt>
                        <dd class="font-medium text-gray-900 mt-0.5">Guest</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Ime</dt>
                        <dd class="font-medium text-gray-900 mt-0.5">{{ $reservation->user_name }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Email</dt>
                        <dd class="font-medium text-gray-900 mt-0.5 break-all">{{ $reservation->email ?? '—' }}</dd>
                    </div>
                @else
                    @php
                        $agencyAccountEmail = trim((string) ($reservation->user->email ?? ''));
                        $reservationEmail = trim((string) ($reservation->email ?? ''));
                    @endphp
                    <div>
                        <dt class="text-gray-500">Tip korisnika</dt>
                        <dd class="font-medium text-gray-900 mt-0.5">Agencija</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Agencija</dt>
                        <dd class="font-medium text-gray-900 mt-0.5">
                            @if ($reservation->user_id)
                                <a href="{{ route('panel_admin.agencies.show', $reservation->user_id, false) }}"
                                   class="text-red-700 underline">{{ $reservation->user->name ?: '—' }}</a>
                            @else
                                —
                            @endif
                        </dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Email naloga</dt>
                        <dd class="font-medium text-gray-900 mt-0.5 break-all">{{ $agencyAccountEmail !== '' ? $agencyAccountEmail : '—' }}</dd>
                    </div>
                    @if ($reservationEmail !== '' && strcasecmp($reservationEmail, $agencyAccountEmail) !== 0)
                        <div>
                            <dt class="text-gray-500">Email rezervacije</dt>
                            <dd class="font-medium text-gray-900 mt-0.5 break-all">{{ $reservationEmail }}</dd>
                        </div>
                    @endif
                @endif
                @if ($reservation->status === 'paid')
                    <div>
                        <dt class="text-gray-500">Fiskalni JIR</dt>
                        <dd class="font-medium text-gray-900 mt-0.5 break-all">{{ $reservation->fiscal_jir ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-gray-500">Fiskalni IKOF</dt>
                        <dd class="font-medium text-gray-900 mt-0.5 break-all">{{ $reservation->fiscal_ikof ?? '—' }}</dd>
                    </div>
                    <div class="sm:col-span-2">
                        <dt class="text-gray-500">Fiskalni QR</dt>
                        <dd class="font-medium text-gray-900 mt-0.5 break-all">{{ $reservation->fiscal_qr ?? '—' }}</dd>
                    </div>
                @endif
                <div>
                    <dt class="text-gray-500">invoice_sent_at</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">
                        {{ $reservation->invoice_sent_at?->format('d.m.Y. H:i') ?? '—' }}
                    </dd>
                </div>
                <div>
                    <dt class="text-gray-500">email_sent</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">{{ $emailSentLabel }} ({{ (int) $reservation->email_sent }})</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Kreirano</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">{{ $reservation->created_at?->format('d.m.Y. H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-gray-500">Ažurirano</dt>
                    <dd class="font-medium text-gray-900 mt-0.5">{{ $reservation->updated_at?->format('d.m.Y. H:i') ?? '—' }}</dd>
                </div>
            </dl>
        </section>
    </div>
</x-admin-panel-layout>
