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
            ['key' => 'report_choice', 'locale' => 'cg', 'text' => 'Izvještaj'],
            ['key' => 'report_choice', 'locale' => 'en', 'text' => 'Report'],
            ['key' => 'report_statistics', 'locale' => 'cg', 'text' => 'Statistika'],
            ['key' => 'report_statistics', 'locale' => 'en', 'text' => 'Statistics'],
            ['key' => 'report_financial_card', 'locale' => 'cg', 'text' => 'Finansijska kartica'],
            ['key' => 'report_financial_card', 'locale' => 'en', 'text' => 'Financial card'],
            ['key' => 'financial_card_title', 'locale' => 'cg', 'text' => 'Finansijska kartica'],
            ['key' => 'financial_card_title', 'locale' => 'en', 'text' => 'Financial card'],
            ['key' => 'financial_card_disclaimer', 'locale' => 'cg', 'text' => 'Ova kartica prikazuje zabilježene uplate, realizovane usluge i korekcije avansa. Nije stanje avansa.'],
            ['key' => 'financial_card_disclaimer', 'locale' => 'en', 'text' => 'This card lists recorded payments, realized services, and advance corrections. It is not the advance balance.'],
            ['key' => 'financial_card_historical_notice', 'locale' => 'cg', 'text' => 'Neke starije rezervacije možda još nisu prikazane na finansijskoj kartici ove agencije. Zabilježeni datum kupovine može se razlikovati od stvarnog datuma plaćanja karticom zbog ranijih administrativnih izmjena.'],
            ['key' => 'financial_card_historical_notice', 'locale' => 'en', 'text' => 'Some older reservations may not yet appear on this agency financial card. The recorded purchase date may differ from the actual card payment date because of earlier administrative adjustments.'],
            ['key' => 'financial_card_undated_topup', 'locale' => 'cg', 'text' => 'Plaćene avansne uplate bez datuma uplate nisu uračunate. Broj je za cijelu agenciju, ne samo za izabrani period: :count.'],
            ['key' => 'financial_card_undated_topup', 'locale' => 'en', 'text' => 'Paid advance top-ups without a payment date are omitted. The count is for the whole agency, not only the selected period: :count.'],
            ['key' => 'financial_card_empty', 'locale' => 'cg', 'text' => 'Nema stavki u izabranom periodu.'],
            ['key' => 'financial_card_empty', 'locale' => 'en', 'text' => 'No entries in the selected period.'],
            ['key' => 'col_date', 'locale' => 'cg', 'text' => 'Datum'],
            ['key' => 'col_date', 'locale' => 'en', 'text' => 'Date'],
            ['key' => 'col_event', 'locale' => 'cg', 'text' => 'Opis'],
            ['key' => 'col_event', 'locale' => 'en', 'text' => 'Description'],
            ['key' => 'col_mtid', 'locale' => 'cg', 'text' => 'Transakcija'],
            ['key' => 'col_mtid', 'locale' => 'en', 'text' => 'Transaction'],
            ['key' => 'col_incoming', 'locale' => 'cg', 'text' => 'Uplate'],
            ['key' => 'col_incoming', 'locale' => 'en', 'text' => 'Incoming'],
            ['key' => 'col_service', 'locale' => 'cg', 'text' => 'Usluge'],
            ['key' => 'col_service', 'locale' => 'en', 'text' => 'Services'],
            ['key' => 'col_correction', 'locale' => 'cg', 'text' => 'Korekcija avansa'],
            ['key' => 'col_correction', 'locale' => 'en', 'text' => 'Advance correction'],
            ['key' => 'event_card_purchase', 'locale' => 'cg', 'text' => 'Plaćanje karticom'],
            ['key' => 'event_card_purchase', 'locale' => 'en', 'text' => 'Card payment'],
            ['key' => 'event_topup', 'locale' => 'cg', 'text' => 'Avansna uplata'],
            ['key' => 'event_topup', 'locale' => 'en', 'text' => 'Advance top-up'],
            ['key' => 'event_service', 'locale' => 'cg', 'text' => 'Rezervacija'],
            ['key' => 'event_service', 'locale' => 'en', 'text' => 'Reservation'],
            ['key' => 'event_correction', 'locale' => 'cg', 'text' => 'Korekcija avansa'],
            ['key' => 'event_correction', 'locale' => 'en', 'text' => 'Advance correction'],
            ['key' => 'total_incoming', 'locale' => 'cg', 'text' => 'Ukupno uplata'],
            ['key' => 'total_incoming', 'locale' => 'en', 'text' => 'Total incoming payments'],
            ['key' => 'total_services', 'locale' => 'cg', 'text' => 'Ukupno usluga'],
            ['key' => 'total_services', 'locale' => 'en', 'text' => 'Total realized services'],
            ['key' => 'total_corrections', 'locale' => 'cg', 'text' => 'Ukupno korekcija avansa'],
            ['key' => 'total_corrections', 'locale' => 'en', 'text' => 'Total advance corrections'],
        ];
    }
};
