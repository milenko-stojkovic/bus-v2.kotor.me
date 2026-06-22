@php
    $k = (array) ($dataset['kpi'] ?? []);
    $f = (array) ($dataset['filters'] ?? []);
    $fmtMoney = fn (float $v) => number_format($v, 2, '.', '').' EUR';
    $fmtPct = fn (float $v) => number_format($v * 100, 1, '.', '').'%';
    $fmtSlotUsageIndex = fn (float $v) => number_format($v, 2, '.', '');
    $st = \App\Support\AdminAnalyticsSectionTexts::all();
@endphp
<!doctype html>
<html lang="sr-Latn-ME">
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; }
        .h1 { font-size: 18px; font-weight: 700; margin: 0 0 8px; }
        .muted { color: #555; }
        .kpi { width: 100%; border-collapse: collapse; margin: 12px 0; }
        .kpi td { border: 1px solid #ddd; padding: 6px 8px; }
        h2 { font-size: 14px; margin: 16px 0 6px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #ddd; padding: 5px 6px; vertical-align: top; }
        th { background: #f3f4f6; font-weight: 700; }
    </style>
</head>
<body>
    <div class="h1">Admin analitika — izveštaj</div>
    <div class="muted">
        Period: {{ $f['date_from'] ?? '—' }} — {{ $f['date_to'] ?? '—' }}<br>
        Uključene besplatne rezervacije: {{ !empty($f['include_free']) ? 'DA' : 'NE' }}
    </div>

    <h2>KPI</h2>
    <div class="muted">{{ $st['kpi'] ?? '' }}</div>
    @php($lmPdf = (array) ($dataset['limo'] ?? []))
    @php($rkPdf = (array) ($dataset['reservation_kinds'] ?? []))
    @php($dtPdf = (array) ($rkPdf['daily_ticket'] ?? []))
    @php($dtLimoPdf = (array) ($dtPdf['limo'] ?? []))
    @php($dtBusesPdf = (array) ($dtPdf['buses'] ?? []))
    <table class="kpi">
        <tr>
            <td>Termini — broj / prihod</td>
            <td>{{ (int)(($rkPdf['time_slots']['count'] ?? 0)) }} / {{ $fmtMoney((float)(($rkPdf['time_slots']['revenue'] ?? 0))) }}</td>
            <td>Dnevna naknada ukupno — broj / prihod</td>
            <td>{{ (int)(($dtPdf['count'] ?? 0)) }} / {{ $fmtMoney((float)(($dtPdf['revenue'] ?? 0))) }}</td>
        </tr>
        <tr>
            <td>DN — Limo — broj / prihod</td>
            <td>{{ (int)(($dtLimoPdf['count'] ?? 0)) }} / {{ $fmtMoney((float)(($dtLimoPdf['revenue'] ?? 0))) }}</td>
            <td>DN — Autobusi — broj / prihod</td>
            <td>{{ (int)(($dtBusesPdf['count'] ?? 0)) }} / {{ $fmtMoney((float)(($dtBusesPdf['revenue'] ?? 0))) }}</td>
        </tr>
        <tr>
            <td colspan="2"><strong>Ukupan prihod (rezervacije, paid)</strong></td>
            <td colspan="2"><strong>{{ $fmtMoney((float)($k['revenue_reservations'] ?? 0)) }}</strong></td>
        </tr>
        <tr>
            <td>Prihod od Limo pickup-a (evidencija)</td><td>{{ $fmtMoney((float)($lmPdf['revenue_total'] ?? 0)) }}</td>
            <td>Rezervacije + Limo pickup</td><td>{{ $fmtMoney((float)($k['revenue_grand_total'] ?? 0)) }}</td>
        </tr>
        <tr>
            <td>Rezervacije (ukupno)</td><td>{{ (int)($k['reservations_total'] ?? 0) }}</td>
            <td>Paid / Free</td><td>{{ (int)($k['paid_reservations'] ?? 0) }} / {{ (int)($k['free_reservations'] ?? 0) }}</td>
        </tr>
        <tr>
            <td>Prosječno (paid)</td><td>{{ $fmtMoney((float)($k['avg_revenue_per_paid'] ?? 0)) }}</td>
            <td></td><td></td>
        </tr>
        <tr>
            <td>Zauzeti slotovi (samo termini)</td><td>{{ (int)($k['occupied_slots_total'] ?? 0) }}</td>
            <td>Prosječno zauzeće termina</td><td>{{ $fmtSlotUsageIndex((float)($k['avg_occupancy_slot_level'] ?? 0)) }}</td>
        </tr>
        <tr>
            <td colspan="4" class="muted" style="font-size:10px;padding-top:0;padding-bottom:6px">
                {{ $st['slot_usage_index'] ?? '' }}
            </td>
        </tr>
        <tr>
            <td>Blokirani slotovi</td><td>{{ (int)($k['blocked_slot_rows'] ?? 0) }}</td>
            <td>Izgubljeni kapacitet</td><td>{{ $fmtPct((float)($k['blocked_capacity_pct'] ?? 0)) }}</td>
        </tr>
    </table>

    <h2>Dnevna naknada</h2>
    <div class="muted">{{ $st['daily_fee'] ?? '' }}</div>
    <table>
        <thead>
            <tr><th>Kategorija</th><th>Broj vozila</th><th>Prihod</th></tr>
        </thead>
        <tbody>
            @foreach (($dataset['daily_fee']['rows'] ?? []) as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td style="text-align:right">{{ (int) ($row['count'] ?? 0) }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float) ($row['revenue'] ?? 0)) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Limo pickup (evidencija)</h2>
    <div class="muted">{{ $st['limo'] ?? '' }}</div>
    <table class="kpi">
        <tr>
            <td>Prihod (Limo)</td><td>{{ $fmtMoney((float)($lmPdf['revenue_total'] ?? 0)) }}</td>
            <td>Broj pickup-a</td><td>{{ (int)($lmPdf['pickup_count'] ?? 0) }}</td>
        </tr>
        <tr>
            <td>QR pickup</td><td>{{ (int)($lmPdf['qr_count'] ?? 0) }}</td>
            <td>Pickup preko tablice</td><td>{{ (int)($lmPdf['plate_count'] ?? 0) }}</td>
        </tr>
        <tr>
            <td>Fiskalizovano</td><td>{{ (int)($lmPdf['fiscalized_count'] ?? 0) }}</td>
            <td>U obradi</td><td>{{ (int)($lmPdf['pending_fiscal_count'] ?? 0) }}</td>
        </tr>
        <tr>
            <td>Greška fiskalizacije</td><td>{{ (int)($lmPdf['fiscal_failed_count'] ?? 0) }}</td>
            <td></td><td></td>
        </tr>
    </table>

    <h2>Trend po danima</h2>
    <div class="muted">{{ $st['trend'] ?? '' }}</div>
    <table>
        <thead>
            <tr>
                <th>Datum</th><th>Rez.</th><th>Paid</th><th>Free</th><th>Zauzeti slotovi</th><th>Prihod</th>
            </tr>
        </thead>
        <tbody>
            @foreach (($dataset['trend_by_day'] ?? []) as $row)
                <tr>
                    <td>{{ $row['date'] }}</td>
                    <td style="text-align:right">{{ $row['reservations'] }}</td>
                    <td style="text-align:right">{{ $row['paid'] }}</td>
                    <td style="text-align:right">{{ $row['free'] }}</td>
                    <td style="text-align:right">{{ $row['occupied_slots'] }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float)$row['revenue']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Delovi dana</h2>
    <div class="muted">{{ $st['day_parts'] ?? '' }} Samo termini; dnevna naknada u posebnoj sekciji.</div>
    <table>
        <thead>
            <tr>
                <th>Prozor</th><th>Rez.</th><th>Zauzeti slotovi</th><th>Prihod</th><th>Udeo zauzetosti</th>
            </tr>
        </thead>
        <tbody>
            @foreach (($dataset['day_parts'] ?? []) as $row)
                <tr>
                    <td>{{ $row['label'] }}</td>
                    <td style="text-align:right">{{ $row['reservations'] }}</td>
                    <td style="text-align:right">{{ $row['occupied_slots'] }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float)$row['revenue']) }}</td>
                    <td style="text-align:right">{{ $fmtPct((float)$row['share_occupied']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Po tipovima vozila</h2>
    <div class="muted">{{ $st['vehicle_types'] ?? '' }}</div>
    <table>
        <thead>
            <tr>
                <th>Tip</th><th>Rez.</th><th>Zauzeti slotovi</th><th>Prihod</th><th>Prosječno</th>
            </tr>
        </thead>
        <tbody>
            @foreach (($dataset['by_vehicle_type'] ?? []) as $row)
                <tr>
                    <td>{{ $row['name'] }}</td>
                    <td style="text-align:right">{{ $row['reservations'] }}</td>
                    <td style="text-align:right">{{ $row['occupied_slots'] }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float)$row['revenue']) }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float)$row['avg_revenue']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Po agencijama</h2>
    <div class="muted">{{ $st['agencies'] ?? '' }}</div>
    <table>
        <thead>
            <tr>
                <th>Agencija</th>
                <th>Prihod</th>
                <th>Termini</th>
                <th>Prihod (T)</th>
                <th>DN Limo</th>
                <th>Prihod (L)</th>
                <th>DN Autobusi</th>
                <th>Prihod (A)</th>
                <th>DN ukupno</th>
                <th>Prihod (DN)</th>
                <th>Rez.</th>
                <th>Zauzeti slotovi</th>
            </tr>
        </thead>
        <tbody>
            @foreach (($dataset['by_agency'] ?? []) as $row)
                <tr>
                    <td>{{ $row['agency'] }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float) $row['revenue']) }}</td>
                    <td style="text-align:right">{{ (int) ($row['time_slots_count'] ?? 0) }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float) ($row['time_slots_revenue'] ?? 0)) }}</td>
                    <td style="text-align:right">{{ (int) ($row['daily_fee_limo_count'] ?? 0) }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float) ($row['daily_fee_limo_revenue'] ?? 0)) }}</td>
                    <td style="text-align:right">{{ (int) ($row['daily_fee_buses_count'] ?? 0) }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float) ($row['daily_fee_buses_revenue'] ?? 0)) }}</td>
                    <td style="text-align:right">{{ (int) ($row['daily_ticket_count'] ?? 0) }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float) ($row['daily_ticket_revenue'] ?? 0)) }}</td>
                    <td style="text-align:right">{{ (int) $row['reservations_total'] }}</td>
                    <td style="text-align:right">{{ (int) $row['occupied_slots'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Besplatne rezervacije po agencijama</h2>
    <div class="muted">{{ $st['admin_free_agencies'] ?? '' }}</div>
    <table>
        <thead>
            <tr>
                <th>Agencija</th>
                <th>Rez.</th>
                <th>Zauzeti slotovi</th>
                <th>Najčešći tip</th>
            </tr>
        </thead>
        <tbody>
            @foreach (($dataset['admin_free_by_agency'] ?? []) as $row)
                <tr>
                    <td>{{ $row['agency'] }}</td>
                    <td style="text-align:right">{{ (int) $row['reservations'] }}</td>
                    <td style="text-align:right">{{ (int) $row['occupied_slots'] }}</td>
                    <td>{{ $row['top_vehicle_type'] }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <h2>Po državama</h2>
    <div class="muted">{{ $st['countries'] ?? '' }}</div>
    <table>
        <thead>
            <tr>
                <th>Država</th><th>Rez.</th><th>Paid</th><th>Free</th><th>Prihod</th>
            </tr>
        </thead>
        <tbody>
            @foreach (($dataset['by_country'] ?? []) as $row)
                <tr>
                    <td>{{ $row['country'] }}</td>
                    <td style="text-align:right">{{ $row['reservations'] }}</td>
                    <td style="text-align:right">{{ $row['paid'] }}</td>
                    <td style="text-align:right">{{ $row['free'] }}</td>
                    <td style="text-align:right">{{ $fmtMoney((float)$row['revenue']) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    @php($pf = (array)($dataset['paid_vs_free'] ?? []))
    <h2>Paid vs Free</h2>
    <div class="muted">{{ $st['paid_vs_free'] ?? '' }}</div>
    <table>
        <tbody>
            <tr><td>Paid rezervacije</td><td style="text-align:right">{{ (int)($pf['paid_reservations'] ?? 0) }}</td></tr>
            <tr><td>Free rezervacije</td><td style="text-align:right">{{ (int)($pf['free_reservations'] ?? 0) }}</td></tr>
            <tr><td>Zauzeti slotovi (paid)</td><td style="text-align:right">{{ (int)($pf['paid_occupied_slots'] ?? 0) }}</td></tr>
            <tr><td>Zauzeti slotovi (free)</td><td style="text-align:right">{{ (int)($pf['free_occupied_slots'] ?? 0) }}</td></tr>
            <tr><td>Prihod (paid)</td><td style="text-align:right">{{ $fmtMoney((float)($pf['paid_revenue'] ?? 0)) }}</td></tr>
            <tr><td>% zauzetosti koje troše free (slot-level)</td><td style="text-align:right">{{ $fmtPct((float)($pf['free_capacity_pct_by_slots'] ?? 0)) }}</td></tr>
        </tbody>
    </table>

    @php($b = (array)($dataset['blocking'] ?? []))
    <h2>Blokiranje</h2>
    <div class="muted">{{ $st['blocking'] ?? '' }}</div>
    <table>
        <tbody>
            <tr><td>Blokirani slotovi (redovi)</td><td style="text-align:right">{{ (int)($b['blocked_slot_rows'] ?? 0) }}</td></tr>
            <tr><td>Potpuno blokirani dani</td><td style="text-align:right">{{ (int)($b['fully_blocked_days'] ?? 0) }}</td></tr>
            <tr><td>% izgubljenog kapaciteta</td><td style="text-align:right">{{ $fmtPct((float)($b['blocked_capacity_pct'] ?? 0)) }}</td></tr>
        </tbody>
    </table>

    @php($o = (array)($dataset['ops'] ?? []))
    <h2>Operativni problemi / recovery</h2>
    <div class="muted">{{ $st['ops'] ?? '' }}</div>
    <table>
        <tbody>
            <tr><td>Failed payment pokušaji</td><td style="text-align:right">{{ (int)($o['failed_payments'] ?? 0) }}</td></tr>
            <tr><td>Expired payment pokušaji</td><td style="text-align:right">{{ (int)($o['expired_payments'] ?? 0) }}</td></tr>
            <tr><td>Late success</td><td style="text-align:right">{{ (int)($o['late_success'] ?? 0) }}</td></tr>
            <tr><td>Paid bez fiscal JIR</td><td style="text-align:right">{{ (int)($o['paid_without_fiscal_jir'] ?? 0) }}</td></tr>
            <tr><td>Unresolved post-fiscal</td><td style="text-align:right">{{ (int)($o['unresolved_post_fiscal'] ?? 0) }}</td></tr>
            <tr><td>Resolved post-fiscal (u periodu)</td><td style="text-align:right">{{ (int)($o['resolved_post_fiscal'] ?? 0) }}</td></tr>
            <tr>
                <td title="Rezervacije koje su plaćene, ali se u potpunosti nalaze u besplatnim terminima (najčešće rezultat administrativne izmjene).">Paid rezervacije u free terminima</td>
                <td style="text-align:right">{{ (int)($o['paid_reservations_fully_in_free_zone'] ?? 0) }}</td>
            </tr>
            <tr>
                <td colspan="2" class="muted" style="font-size:10px;padding-top:0;padding-bottom:6px">
                    Rezervacije koje su plaćene, ali se u potpunosti nalaze u besplatnim terminima (najčešće rezultat administrativne izmjene).
                </td>
            </tr>
            <tr>
                <td title="Sumnjivi slučajevi gdje su za isti datum i iste tablice plaćene rezervacije sa bar jednim zajedničkim terminom.">Duplo plaćanje istog termina</td>
                <td style="text-align:right">{{ (int)($o['double_paid_same_slot_pairs'] ?? 0) }}</td>
            </tr>
            <tr>
                <td colspan="2" class="muted" style="font-size:10px;padding-top:0;padding-bottom:6px">
                    Sumnjivi slučajevi gdje su za isti datum i iste tablice plaćene rezervacije sa bar jednim zajedničkim terminom.
                </td>
            </tr>
        </tbody>
    </table>
</body>
</html>

