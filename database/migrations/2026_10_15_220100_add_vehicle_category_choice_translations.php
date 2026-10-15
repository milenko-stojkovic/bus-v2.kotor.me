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
            ['group' => 'panel', 'key' => 'vehicle_category_choice_intro', 'locale' => 'cg', 'text' => 'Ova registarska tablica je već evidentirana u drugoj kategoriji. Izaberite jednu opciju. Kategorija koju ste unijeli ne primjenjuje se automatski.'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_intro', 'locale' => 'en', 'text' => 'This registration plate is already recorded in another category. Choose one option. The category you entered is not applied automatically.'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_recorded_label', 'locale' => 'cg', 'text' => 'Evidentirana kategorija'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_recorded_label', 'locale' => 'en', 'text' => 'Recorded category'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_submitted_label', 'locale' => 'cg', 'text' => 'Kategorija koju ste unijeli'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_submitted_label', 'locale' => 'en', 'text' => 'Category you entered'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_use_recorded', 'locale' => 'cg', 'text' => 'Dodaj vozilo u evidentiranoj kategoriji'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_use_recorded', 'locale' => 'en', 'text' => 'Add the vehicle in the recorded category'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_use_recorded_help', 'locale' => 'cg', 'text' => 'Dokument nije potreban. Vozilo se dodaje samo u kategoriji koja je već evidentirana za ovu tablicu.'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_use_recorded_help', 'locale' => 'en', 'text' => 'No document is required. The vehicle is added only in the category already recorded for this plate.'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_request_correction', 'locale' => 'cg', 'text' => 'Zatraži ispravku kategorije'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_request_correction', 'locale' => 'en', 'text' => 'Request a category correction'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_request_help', 'locale' => 'cg', 'text' => 'Priložite fotografije saobraćajne dozvole. Kategorija se mijenja tek kada administrator odobri zahtjev.'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_request_help', 'locale' => 'en', 'text' => 'Attach photographs of the registration certificate. The category changes only after an administrator approves the request.'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_stale', 'locale' => 'cg', 'text' => 'Evidentirana kategorija za ovu tablicu je promijenjena. Ništa nije sačuvano. Izaberite ponovo.'],
            ['group' => 'panel', 'key' => 'vehicle_category_choice_stale', 'locale' => 'en', 'text' => 'The recorded category for this plate changed. Nothing was saved. Choose again.'],
        ];
    }
};
