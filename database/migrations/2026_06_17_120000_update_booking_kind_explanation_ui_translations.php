<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['group' => 'panel', 'key' => 'booking_kind_expl_time_slots', 'locale' => 'en', 'text' => 'Time slots — if you want reserved arrival and departure times at :benovo_link (mandatory location). If your preferred time slots are unavailable, choose the daily fee.'],
            ['group' => 'panel', 'key' => 'booking_kind_expl_time_slots', 'locale' => 'cg', 'text' => 'Termini — ako želite unaprijed rezervisano vrijeme dolaska i odlaska na lokaciji :benovo_link (obavezna lokacija). Ako Vam nisu na raspolaganju željeni termini odaberite dnevnu naknadu.'],
            ['group' => 'panel', 'key' => 'booking_kind_expl_daily_ticket', 'locale' => 'en', 'text' => 'Daily fee — if an exact time is not important or you plan to visit several locations during the day, e.g. Perast, Risan, the Kotor–Lovćen cable car… When visiting the Old Town, passenger pick-up and drop-off use the :autoboka_link and :puc_link parking areas.'],
            ['group' => 'panel', 'key' => 'booking_kind_expl_daily_ticket', 'locale' => 'cg', 'text' => 'Dnevna naknada — ako vam nije bitan tačan termin ili planirate obilazak više lokacija tokom dana, npr. Perast, Risan, žičara Kotor - Lovćen... U slučaju da želite da posjetite Stari grad, za iskrcaj i ukrcaj putnika koriste se lokacije parkinga :autoboka_link i :puc_link.'],
        ];

        $now = now();
        foreach ($rows as $row) {
            DB::table('ui_translations')->updateOrInsert(
                ['group' => $row['group'], 'key' => $row['key'], 'locale' => $row['locale']],
                ['text' => $row['text'], 'updated_at' => $now, 'created_at' => $now],
            );
        }

        Cache::forget('ui_translations:group=panel:locale=cg');
        Cache::forget('ui_translations:group=panel:locale=en');
        foreach (['booking_kind_expl_time_slots', 'booking_kind_expl_daily_ticket'] as $key) {
            Cache::forget('ui_translations:any:group=panel:key='.$key);
        }
    }

    public function down(): void
    {
        // Previous copy intentionally not restored — use git history / seeder if rollback needed.
    }
};
