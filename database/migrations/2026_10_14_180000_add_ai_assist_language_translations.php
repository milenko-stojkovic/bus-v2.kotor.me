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

        foreach (['landing', 'panel'] as $group) {
            foreach (['cg', 'en'] as $locale) {
                Cache::forget("ui_translations:group={$group}:locale={$locale}");
            }
        }
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where('key', 'ai_assist_language')
            ->whereIn('group', ['landing', 'panel'])
            ->delete();

        foreach (['landing', 'panel'] as $group) {
            foreach (['cg', 'en'] as $locale) {
                Cache::forget("ui_translations:group={$group}:locale={$locale}");
            }
            Cache::forget("ui_translations:any:group={$group}:key=ai_assist_language");
        }
    }

    /**
     * @return list<array{group: string, key: string, locale: string, text: string}>
     */
    private function rows(): array
    {
        return [
            ['group' => 'landing', 'key' => 'ai_assist_language', 'locale' => 'cg', 'text' => 'Možete postavljati pitanja i dobijati odgovore na svom jeziku.'],
            ['group' => 'landing', 'key' => 'ai_assist_language', 'locale' => 'en', 'text' => 'You can ask questions and receive answers in your preferred language.'],
            ['group' => 'panel', 'key' => 'ai_assist_language', 'locale' => 'cg', 'text' => 'Možete postavljati pitanja i dobijati odgovore na svom jeziku.'],
            ['group' => 'panel', 'key' => 'ai_assist_language', 'locale' => 'en', 'text' => 'You can ask questions and receive answers in your preferred language.'],
        ];
    }
};
