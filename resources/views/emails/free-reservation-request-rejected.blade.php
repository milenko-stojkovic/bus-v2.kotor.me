@if ($agencyLocale === 'en')
<p>Dear {{ $institutionName }},</p>

<p>Your free reservation request was rejected.</p>

<p>
    Request: #{{ $requestId }}<br>
    Date: {{ $reservationDate !== '' ? $reservationDate : '—' }}
</p>

<p>Rejection reason:<br>
<span style="white-space: pre-wrap;">{{ $rejectionReason }}</span></p>

<p>You can review the request on bus.kotor.me, under Free reservations.</p>

<p>Kind regards,<br>
Municipality of Kotor</p>
@else
<p>Poštovani {{ $institutionName }},</p>

<p>Vaš zahtjev za besplatnu rezervaciju je odbijen.</p>

<p>
    Zahtjev: #{{ $requestId }}<br>
    Datum: {{ $reservationDate !== '' ? $reservationDate : '—' }}
</p>

<p>Razlog odbijanja:<br>
<span style="white-space: pre-wrap;">{{ $rejectionReason }}</span></p>

<p>Zahtjev možete pregledati na bus.kotor.me, u kartici Besplatne rezervacije.</p>

<p>S poštovanjem,<br>
Opština Kotor</p>
@endif
