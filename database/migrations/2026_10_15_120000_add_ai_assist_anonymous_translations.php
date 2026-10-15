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
        $keys = array_values(array_unique(array_column($this->rows(), 'key')));
        DB::table('ui_translations')
            ->whereIn('key', $keys)
            ->whereIn('group', ['landing', 'panel'])
            ->delete();

        foreach (['landing', 'panel'] as $group) {
            foreach (['cg', 'en'] as $locale) {
                Cache::forget("ui_translations:group={$group}:locale={$locale}");
            }
            foreach ($keys as $key) {
                Cache::forget("ui_translations:any:group={$group}:key={$key}");
            }
        }
    }

    /**
     * @return list<array{group: string, key: string, locale: string, text: string}>
     */
    private function rows(): array
    {
        $texts = [
            'ai_assist_anonymous_hint' => [
                'cg' => 'Ako ChatGPT prijavi grešku provjere, koristite ovu opciju.',
                'en' => 'If ChatGPT shows a verification error, use this option.',
            ],
            'ai_assist_anonymous_open' => [
                'cg' => 'Kopiraj i otvori ChatGPT',
                'en' => 'Copy and open ChatGPT',
            ],
            'ai_assist_anonymous_paste' => [
                'cg' => 'U ChatGPT-u nalijepite tekst (Ctrl+V ili Zalijepi) i pritisnite Enter.',
                'en' => 'In ChatGPT, paste the text (Ctrl+V or Paste) and press Enter.',
            ],
            'ai_assist_anonymous_copied' => [
                'cg' => 'Tekst je kopiran.',
                'en' => 'Text copied.',
            ],
            'ai_assist_anonymous_copy_failed' => [
                'cg' => 'Automatsko kopiranje nije uspjelo. Kopirajte tekst iz polja ispod.',
                'en' => 'Automatic copying failed. Copy the text from the box below.',
            ],
        ];

        $rows = [];
        foreach (['landing', 'panel'] as $group) {
            foreach ($texts as $key => $byLocale) {
                foreach ($byLocale as $locale => $text) {
                    $rows[] = ['group' => $group, 'key' => $key, 'locale' => $locale, 'text' => $text];
                }
            }
        }

        return $rows;
    }
};
