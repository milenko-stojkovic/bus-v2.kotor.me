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
        }

        foreach (['cg', 'en'] as $locale) {
            Cache::forget('ui_translations:group=reservation:locale='.$locale);
        }
        foreach (array_unique(array_column($this->rows(), 'key')) as $key) {
            Cache::forget('ui_translations:any:group=reservation:key='.$key);
        }
    }

    public function down(): void
    {
        $keys = array_values(array_unique(array_column($this->rows(), 'key')));
        DB::table('ui_translations')
            ->where('group', 'reservation')
            ->whereIn('key', $keys)
            ->delete();

        foreach (['cg', 'en'] as $locale) {
            Cache::forget('ui_translations:group=reservation:locale='.$locale);
        }
        foreach ($keys as $key) {
            Cache::forget('ui_translations:any:group=reservation:key='.$key);
        }
    }

    /**
     * @return list<array{group: string, key: string, locale: string, text: string}>
     */
    private function rows(): array
    {
        return [
            ['group' => 'reservation', 'key' => 'tariff_card_blackout', 'locale' => 'en', 'text' => 'Card payment is unavailable until midnight because a price change takes effect at 00:00. You can try again after the new prices apply. Advance payment and free reservations are not affected.'],
            ['group' => 'reservation', 'key' => 'tariff_card_blackout', 'locale' => 'cg', 'text' => 'Plaćanje karticom nije dostupno do ponoći jer nova tarifa počinje u 00:00. Nakon toga možete pokušati ponovo po novim cijenama. Plaćanje iz avansa i besplatne rezervacije nisu obustavljeni.'],
            ['group' => 'reservation', 'key' => 'tariff_advance_warning', 'locale' => 'en', 'text' => 'Prices change at midnight (00:00). The amount charged is the price in effect when the payment is completed. Until midnight the current price is used. Your advance balance must also cover the price that applies from midnight.'],
            ['group' => 'reservation', 'key' => 'tariff_advance_warning', 'locale' => 'cg', 'text' => 'Cijene se mijenjaju u ponoć (00:00). Naplaćuje se cijena koja važi u trenutku plaćanja. Do ponoći važi trenutna cijena. Raspoloživi avans mora pokrivati i cijenu koja važi od ponoći.'],
            ['group' => 'reservation', 'key' => 'tariff_confirm_amount', 'locale' => 'en', 'text' => 'The price has changed. Confirm the amount of :amount EUR before payment.'],
            ['group' => 'reservation', 'key' => 'tariff_confirm_amount', 'locale' => 'cg', 'text' => 'Cijena je promijenjena. Potvrdite iznos od :amount EUR prije plaćanja.'],
            ['group' => 'reservation', 'key' => 'tariff_advance_need_incoming', 'locale' => 'en', 'text' => 'Your advance balance must cover both the current price (:current EUR) and the price from midnight (:incoming EUR).'],
            ['group' => 'reservation', 'key' => 'tariff_advance_need_incoming', 'locale' => 'cg', 'text' => 'Raspoloživi avans mora pokrivati i trenutnu cijenu (:current EUR) i cijenu od ponoći (:incoming EUR).'],
        ];
    }
};
