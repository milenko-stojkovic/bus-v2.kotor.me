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
            Cache::forget('ui_translations:any:group='.$row['group'].':key='.$row['key']);
        }

        foreach (['cg', 'en'] as $locale) {
            Cache::forget("ui_translations:group=panel:locale={$locale}");
        }
    }

    public function down(): void
    {
        $keys = array_values(array_unique(array_column($this->rows(), 'key')));
        DB::table('ui_translations')
            ->whereIn('key', $keys)
            ->where('group', 'panel')
            ->delete();

        foreach (['cg', 'en'] as $locale) {
            Cache::forget("ui_translations:group=panel:locale={$locale}");
        }
        foreach ($keys as $key) {
            Cache::forget("ui_translations:any:group=panel:key={$key}");
        }
    }

    /**
     * @return list<array{group: string, key: string, locale: string, text: string}>
     */
    private function rows(): array
    {
        return [
            ['group' => 'panel', 'key' => 'vehicle_plate_category_conflict_blocked', 'locale' => 'cg', 'text' => 'Ova registarska tablica već ima neusaglašene kategorije vozila. Novo vozilo sa ovom tablicom trenutno nije moguće dodati. Pišite na bus@kotor.me.'],
            ['group' => 'panel', 'key' => 'vehicle_plate_category_conflict_blocked', 'locale' => 'en', 'text' => 'This registration plate already has conflicting vehicle categories. A new vehicle with this plate cannot be added right now. Write to bus@kotor.me.'],
            ['group' => 'panel', 'key' => 'vehicle_plate_category_mismatch_blocked', 'locale' => 'cg', 'text' => 'Ova registarska tablica je već evidentirana u drugoj kategoriji vozila. Nije je moguće dodati u drugoj kategoriji. Pišite na bus@kotor.me.'],
            ['group' => 'panel', 'key' => 'vehicle_plate_category_mismatch_blocked', 'locale' => 'en', 'text' => 'This registration plate is already recorded in a different vehicle category. It cannot be added under another category. Write to bus@kotor.me.'],
            ['group' => 'panel', 'key' => 'vehicle_plate_lock_timeout', 'locale' => 'cg', 'text' => 'Vozilo nije sačuvano jer je druga izmjena ove tablice u toku. Pokušajte ponovo.'],
            ['group' => 'panel', 'key' => 'vehicle_plate_lock_timeout', 'locale' => 'en', 'text' => 'The vehicle could not be saved because another update for this plate is in progress. Please try again.'],
        ];
    }
};
