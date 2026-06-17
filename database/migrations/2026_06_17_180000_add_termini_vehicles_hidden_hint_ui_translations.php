<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['group' => 'booking', 'key' => 'termini_vehicles_hidden_hint', 'locale' => 'en', 'text' => 'Some vehicles are not shown because they already have a reservation on the selected date with the same arrival or departure time.'],
            ['group' => 'booking', 'key' => 'termini_vehicles_hidden_hint', 'locale' => 'cg', 'text' => 'Neka vozila nisu prikazana jer za odabrani datum već imaju rezervaciju sa istim vremenom dolaska ili odlaska.'],
        ];

        foreach ($rows as $row) {
            DB::table('ui_translations')->updateOrInsert(
                ['group' => $row['group'], 'key' => $row['key'], 'locale' => $row['locale']],
                ['text' => $row['text'], 'created_at' => now(), 'updated_at' => now()],
            );
        }
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where('group', 'booking')
            ->where('key', 'termini_vehicles_hidden_hint')
            ->delete();
    }
};
