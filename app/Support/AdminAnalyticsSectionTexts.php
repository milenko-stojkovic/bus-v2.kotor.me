<?php

namespace App\Support;

final class AdminAnalyticsSectionTexts
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return [
            'kpi' => 'Brzi pregled ključnih pokazatelja za izabrani period. Termini i dnevna naknada (Limo / Autobusi) prikazani odvojeno; prihod samo od plaćenih rezervacija. Limo pickup (evidencija) je poseban proizvod i ne ulazi u prihod rezervacija.',
            'daily_fee' => 'Plaćene dnevne naknade grupisane po tipu vozila. Limo = putničko vozilo (4+1–7+1) i mini bus (8+1) prema `controlDailyFeeListVehicleTypeIds()`; Autobusi = ostale kategorije. Dnevna naknada nema termine i ne utiče na popunjenost slotova.',
            'trend' => 'Dnevni pregled prihoda, rezervacija i zauzetih slotova. Zauzeti slotovi uključuju samo rezervacije sa terminima.',
            'day_parts' => 'Kako su termini i prihod raspoređeni kroz jutro, dan i veče (samo `time_slots`). Prozor se određuje po drop-off terminu; dnevna naknada je u posebnoj sekciji.',
            'vehicle_types' => 'Pregled rezervacija, zauzetosti i prihoda po kategoriji vozila.',
            'agencies' => 'Pregled prihoda, termina i dnevnih naknada (Limo / Autobusi) po registrovanim agencijama. Zauzeti slotovi = samo termini.',
            'admin_free_agencies' => 'Pregled besplatnih rezervacija koje su kreirali administratori, grupisanih po agencijama.',
            'countries' => 'Pregled rezervacija i prihoda po državi (paid/free razdvojeno).',
            'paid_vs_free' => 'Odnos plaćenih i besplatnih rezervacija i njihov uticaj na zauzetost.',
            'blocking' => 'Kapacitet izgubljen zbog administrativnog blokiranja termina i dana.',
            'ops' => 'Operativni signali o problematičnim pokušajima plaćanja i fiskalnim kašnjenjima.',
            'advance_balances' => 'Pregled trenutnog stanja neiskorišćenih avansnih sredstava po agencijama. Ovaj iznos ne predstavlja prihod, već evidenciju raspoloživih sredstava za buduće usluge.',
            'limo' => 'Limo pickup događaji (QR/tablica) nisu rezervacije parkinga niti dnevna naknada — prihod se računa iz `limo_pickup_events` za izabrani period (`occurred_at`, zona Europe/Podgorica). Uključeni statusi: pending_fiscal, fiscalized, fiscal_failed; incident isključen.',
        ];
    }
}

