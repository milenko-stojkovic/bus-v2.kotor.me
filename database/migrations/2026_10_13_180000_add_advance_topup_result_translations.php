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
                ['group' => $row['group'], 'key' => $row['key'], 'locale' => $row['locale']],
                ['text' => $row['text'], 'updated_at' => $now, 'created_at' => $now],
            );
            Cache::forget('ui_translations:any:group=panel:key='.$row['key']);
        }

        Cache::forget('ui_translations:group=panel:locale=cg');
        Cache::forget('ui_translations:group=panel:locale=en');
    }

    public function down(): void
    {
        $keys = array_values(array_unique(array_column($this->rows(), 'key')));
        DB::table('ui_translations')
            ->where('group', 'panel')
            ->whereIn('key', $keys)
            ->delete();

        Cache::forget('ui_translations:group=panel:locale=cg');
        Cache::forget('ui_translations:group=panel:locale=en');
        foreach ($keys as $key) {
            Cache::forget('ui_translations:any:group=panel:key='.$key);
        }
    }

    /**
     * @return list<array{group: string, key: string, locale: string, text: string}>
     */
    private function rows(): array
    {
        return [
            ['group' => 'panel', 'key' => 'advance_topup_success_detail', 'locale' => 'en', 'text' => 'The amount has been added to your available advance.'],
            ['group' => 'panel', 'key' => 'advance_topup_success_detail', 'locale' => 'cg', 'text' => 'Iznos je dodat na raspoloživi avans.'],
            ['group' => 'panel', 'key' => 'advance_topup_pending_title', 'locale' => 'en', 'text' => 'Advance top-up is being processed'],
            ['group' => 'panel', 'key' => 'advance_topup_pending_title', 'locale' => 'cg', 'text' => 'Avansna uplata se obrađuje'],
            ['group' => 'panel', 'key' => 'advance_topup_back', 'locale' => 'en', 'text' => 'Back to advance'],
            ['group' => 'panel', 'key' => 'advance_topup_back', 'locale' => 'cg', 'text' => 'Nazad na avans'],
            ['group' => 'panel', 'key' => 'advance_topup_cancelled_title', 'locale' => 'en', 'text' => 'The advance top-up was cancelled.'],
            ['group' => 'panel', 'key' => 'advance_topup_cancelled_title', 'locale' => 'cg', 'text' => 'Avansna uplata je otkazana.'],
            ['group' => 'panel', 'key' => 'advance_topup_cancelled_message', 'locale' => 'en', 'text' => 'No funds were added to your advance. You can try again.'],
            ['group' => 'panel', 'key' => 'advance_topup_cancelled_message', 'locale' => 'cg', 'text' => 'Sredstva nisu dodata na avans. Možete pokušati ponovo.'],
            ['group' => 'panel', 'key' => 'advance_topup_declined_title', 'locale' => 'en', 'text' => 'The advance top-up was not approved.'],
            ['group' => 'panel', 'key' => 'advance_topup_declined_title', 'locale' => 'cg', 'text' => 'Avansna uplata nije odobrena.'],
            ['group' => 'panel', 'key' => 'advance_topup_declined_message', 'locale' => 'en', 'text' => 'The bank declined the card authorization. No funds were added to your advance.'],
            ['group' => 'panel', 'key' => 'advance_topup_declined_message', 'locale' => 'cg', 'text' => 'Banka je odbila autorizaciju kartice. Sredstva nisu dodata na avans.'],
            ['group' => 'panel', 'key' => 'advance_topup_insufficient_funds_title', 'locale' => 'en', 'text' => 'The advance top-up was not approved.'],
            ['group' => 'panel', 'key' => 'advance_topup_insufficient_funds_title', 'locale' => 'cg', 'text' => 'Avansna uplata nije odobrena.'],
            ['group' => 'panel', 'key' => 'advance_topup_insufficient_funds_message', 'locale' => 'en', 'text' => 'There are insufficient funds on the card. No funds were added to your advance.'],
            ['group' => 'panel', 'key' => 'advance_topup_insufficient_funds_message', 'locale' => 'cg', 'text' => 'Na računu nema dovoljno sredstava. Sredstva nisu dodata na avans.'],
            ['group' => 'panel', 'key' => 'advance_topup_3ds_failed_title', 'locale' => 'en', 'text' => 'The advance top-up was not completed.'],
            ['group' => 'panel', 'key' => 'advance_topup_3ds_failed_title', 'locale' => 'cg', 'text' => 'Avansna uplata nije završena.'],
            ['group' => 'panel', 'key' => 'advance_topup_3ds_failed_message', 'locale' => 'en', 'text' => 'Card authentication failed. No funds were added to your advance.'],
            ['group' => 'panel', 'key' => 'advance_topup_3ds_failed_message', 'locale' => 'cg', 'text' => 'Potvrda kartice nije uspjela. Sredstva nisu dodata na avans.'],
            ['group' => 'panel', 'key' => 'advance_topup_card_validation_title', 'locale' => 'en', 'text' => 'The advance top-up was not completed.'],
            ['group' => 'panel', 'key' => 'advance_topup_card_validation_title', 'locale' => 'cg', 'text' => 'Avansna uplata nije završena.'],
            ['group' => 'panel', 'key' => 'advance_topup_card_validation_message', 'locale' => 'en', 'text' => 'The card details were not accepted. No funds were added to your advance.'],
            ['group' => 'panel', 'key' => 'advance_topup_card_validation_message', 'locale' => 'cg', 'text' => 'Podaci kartice nijesu prihvaćeni. Sredstva nisu dodata na avans.'],
            ['group' => 'panel', 'key' => 'advance_topup_expired_title', 'locale' => 'en', 'text' => 'The advance top-up was not completed.'],
            ['group' => 'panel', 'key' => 'advance_topup_expired_title', 'locale' => 'cg', 'text' => 'Avansna uplata nije završena.'],
            ['group' => 'panel', 'key' => 'advance_topup_expired_message', 'locale' => 'en', 'text' => 'The payment session has expired. No funds were added to your advance.'],
            ['group' => 'panel', 'key' => 'advance_topup_expired_message', 'locale' => 'cg', 'text' => 'Vrijeme za plaćanje je isteklo. Sredstva nisu dodata na avans.'],
            ['group' => 'panel', 'key' => 'advance_topup_system_error_message', 'locale' => 'en', 'text' => 'No funds were added to your advance. Please try again in a few minutes.'],
            ['group' => 'panel', 'key' => 'advance_topup_system_error_message', 'locale' => 'cg', 'text' => 'Sredstva nisu dodata na avans. Pokušajte ponovo za nekoliko minuta.'],
            ['group' => 'panel', 'key' => 'advance_topup_unconfirmed_title', 'locale' => 'en', 'text' => 'The advance top-up result could not be confirmed.'],
            ['group' => 'panel', 'key' => 'advance_topup_unconfirmed_title', 'locale' => 'cg', 'text' => 'Rezultat avansne uplate nije bilo moguće potvrditi.'],
            ['group' => 'panel', 'key' => 'advance_topup_unconfirmed_message', 'locale' => 'en', 'text' => 'Check your advance balance. If the card was charged and the amount does not appear, write to bus@kotor.me.'],
            ['group' => 'panel', 'key' => 'advance_topup_unconfirmed_message', 'locale' => 'cg', 'text' => 'Provjerite stanje avansa. Ako je kartica zadužena, a iznos se ne pojavi, pišite na bus@kotor.me.'],
        ];
    }
};
