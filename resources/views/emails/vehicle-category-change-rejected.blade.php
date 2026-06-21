@if ($agencyLocale === 'en')
<p>Dear {{ $agencyName }},</p>

<p>Your vehicle category change request for license plate <strong>{{ $licensePlate }}</strong> has been rejected.</p>

<p>Reason for rejection:<br>
<strong>{{ $rejectionReason }}</strong></p>

<p>Kind regards,<br>
Municipality of Kotor</p>
@else
<p>Poštovani {{ $agencyName }},</p>

<p>Vaš zahtjev za promjenu kategorije vozila registarskih tablica <strong>{{ $licensePlate }}</strong> je odbijen.</p>

<p>Razlog odbijanja:<br>
<strong>{{ $rejectionReason }}</strong></p>

<p>S poštovanjem,<br>
Opština Kotor</p>
@endif
