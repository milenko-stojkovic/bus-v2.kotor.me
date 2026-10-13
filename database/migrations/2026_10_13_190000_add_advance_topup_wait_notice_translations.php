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
            ['group' => 'panel', 'key' => 'advance_topup_wait_notice', 'locale' => 'en', 'text' => 'The advance top-up result has not yet been confirmed. You can return to Advance. If the bank confirms the payment later, the funds will be credited automatically.'],
            ['group' => 'panel', 'key' => 'advance_topup_wait_notice', 'locale' => 'cg', 'text' => 'Rezultat avansne uplate još nije potvrđen. Možete se vratiti na Avans. Ako banka naknadno potvrdi uplatu, sredstva će biti automatski evidentirana.'],
            ['group' => 'panel', 'key' => 'advance_topup_return', 'locale' => 'en', 'text' => 'Return to Advance'],
            ['group' => 'panel', 'key' => 'advance_topup_return', 'locale' => 'cg', 'text' => 'Povratak na Avans'],
        ];
    }
};
