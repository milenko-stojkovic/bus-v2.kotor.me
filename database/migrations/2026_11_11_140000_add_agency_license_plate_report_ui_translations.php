<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        foreach ($this->rows() as $row) {
            DB::table('ui_translations')->updateOrInsert(
                ['group' => 'statistics', 'key' => $row['key'], 'locale' => $row['locale']],
                ['text' => $row['text'], 'updated_at' => $now, 'created_at' => $now],
            );
            Cache::forget('ui_translations:any:group=statistics:key='.$row['key']);
        }

        Cache::forget('ui_translations:group=statistics:locale=cg');
        Cache::forget('ui_translations:group=statistics:locale=en');
    }

    public function down(): void
    {
        $keys = array_values(array_unique(array_column($this->rows(), 'key')));
        DB::table('ui_translations')
            ->where('group', 'statistics')
            ->whereIn('key', $keys)
            ->delete();

        Cache::forget('ui_translations:group=statistics:locale=cg');
        Cache::forget('ui_translations:group=statistics:locale=en');
        foreach ($keys as $key) {
            Cache::forget('ui_translations:any:group=statistics:key='.$key);
        }
    }

    /**
     * @return list<array{key: string, locale: string, text: string}>
     */
    private function rows(): array
    {
        return [
            ['key' => 'report_license_plate', 'locale' => 'cg', 'text' => 'Rezervacije po tablici'],
            ['key' => 'report_license_plate', 'locale' => 'en', 'text' => 'Reservations by license plate'],
            ['key' => 'plate_label', 'locale' => 'cg', 'text' => 'Registarska tablica'],
            ['key' => 'plate_label', 'locale' => 'en', 'text' => 'License plate'],
            ['key' => 'plate_required', 'locale' => 'cg', 'text' => 'Izaberite registarsku tablicu.'],
            ['key' => 'plate_required', 'locale' => 'en', 'text' => 'Choose a license plate.'],
            ['key' => 'plate_invalid', 'locale' => 'cg', 'text' => 'Izaberite registarsku tablicu sa rezervacija ove agencije.'],
            ['key' => 'plate_invalid', 'locale' => 'en', 'text' => 'Choose a license plate from this agency\'s reservations.'],
            ['key' => 'plate_empty', 'locale' => 'cg', 'text' => 'Nema rezervacija u izabranom periodu.'],
            ['key' => 'plate_empty', 'locale' => 'en', 'text' => 'No reservations in the selected period.'],
            ['key' => 'plate_count', 'locale' => 'cg', 'text' => 'Rezervacije: :count'],
            ['key' => 'plate_count', 'locale' => 'en', 'text' => 'Reservations: :count'],
            ['key' => 'plate_amount_total', 'locale' => 'cg', 'text' => 'Ukupno zabilježeni iznos'],
            ['key' => 'plate_amount_total', 'locale' => 'en', 'text' => 'Recorded amount total'],
            ['key' => 'plate_amount_disclaimer', 'locale' => 'cg', 'text' => 'Ukupan iznos sabira zabilježene iznose rezervacija na ovom spisku. Besplatne rezervacije su prikazane kao 0.00. Ovo nije bankom potvrđen zbir uplata.'],
            ['key' => 'plate_amount_disclaimer', 'locale' => 'en', 'text' => 'The amount total adds the recorded reservation amounts in this list. Free reservations are shown as 0.00. This is not a bank-confirmed payment total.'],
            ['key' => 'col_arrival', 'locale' => 'cg', 'text' => 'Dolazak'],
            ['key' => 'col_arrival', 'locale' => 'en', 'text' => 'Arrival'],
            ['key' => 'col_departure', 'locale' => 'cg', 'text' => 'Odlazak'],
            ['key' => 'col_departure', 'locale' => 'en', 'text' => 'Departure'],
            ['key' => 'col_status', 'locale' => 'cg', 'text' => 'Status'],
            ['key' => 'col_status', 'locale' => 'en', 'text' => 'Status'],
            ['key' => 'col_amount', 'locale' => 'cg', 'text' => 'Iznos'],
            ['key' => 'col_amount', 'locale' => 'en', 'text' => 'Amount'],
            ['key' => 'status_paid', 'locale' => 'cg', 'text' => 'Plaćeno'],
            ['key' => 'status_paid', 'locale' => 'en', 'text' => 'Paid'],
            ['key' => 'status_free', 'locale' => 'cg', 'text' => 'Besplatno'],
            ['key' => 'status_free', 'locale' => 'en', 'text' => 'Free'],
        ];
    }
};
