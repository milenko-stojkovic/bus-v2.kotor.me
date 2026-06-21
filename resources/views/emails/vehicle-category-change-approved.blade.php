@if ($agencyLocale === 'en')
<p>Dear {{ $agencyName }},</p>

<p>Your vehicle category change request for license plate <strong>{{ $licensePlate }}</strong> has been approved.</p>

<p>The vehicle is now registered as category:<br>
<strong>{{ $newCategory }}</strong></p>

<p>Kind regards,<br>
Municipality of Kotor</p>
@else
<p>Poštovani {{ $agencyName }},</p>

<p>Vaš zahtjev za promjenu kategorije vozila registarskih tablica <strong>{{ $licensePlate }}</strong> je odobren.</p>

<p>Vozilo je sada evidentirano kao kategorija:<br>
<strong>{{ $newCategory }}</strong></p>

<p>S poštovanjem,<br>
Opština Kotor</p>
@endif
