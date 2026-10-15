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
            ['group' => 'panel', 'key' => 'vehicle_category_active_correct', 'locale' => 'cg', 'text' => 'Ispravi kategoriju'],
            ['group' => 'panel', 'key' => 'vehicle_category_active_correct', 'locale' => 'en', 'text' => 'Correct category'],
            ['group' => 'panel', 'key' => 'vehicle_category_active_new', 'locale' => 'cg', 'text' => 'Nova kategorija'],
            ['group' => 'panel', 'key' => 'vehicle_category_active_new', 'locale' => 'en', 'text' => 'New category'],
            ['group' => 'panel', 'key' => 'vehicle_category_active_pending', 'locale' => 'cg', 'text' => 'Vozilo ostaje dostupno u sadašnjoj kategoriji dok administrator ne pregleda zahtjev.'],
            ['group' => 'panel', 'key' => 'vehicle_category_active_pending', 'locale' => 'en', 'text' => 'The vehicle stays available in its current category while the administrator reviews the request.'],
            ['group' => 'panel', 'key' => 'vehicle_category_active_conflict', 'locale' => 'cg', 'text' => 'Ova registarska tablica već ima neusaglašene kategorije vozila. Ispravka kategorije trenutno nije moguća. Pišite na bus@kotor.me.'],
            ['group' => 'panel', 'key' => 'vehicle_category_active_conflict', 'locale' => 'en', 'text' => 'This registration plate already has conflicting vehicle categories. A category correction cannot be requested right now. Write to bus@kotor.me.'],
        ];
    }
};
