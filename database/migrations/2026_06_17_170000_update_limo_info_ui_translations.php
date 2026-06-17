<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $rows = [
            ['group' => 'panel', 'key' => 'limo_info_pickup_place_2', 'locale' => 'en', 'text' => 'exit from Riva parking area, across from the city market'],
            ['group' => 'panel', 'key' => 'limo_info_pickup_place_2', 'locale' => 'cg', 'text' => 'izlaz iz parking prostora Riva, preko puta gradske pijace'],
            ['group' => 'panel', 'key' => 'limo_info_benovo_ban', 'locale' => 'en', 'text' => 'Limo vehicles (Personal vehicle (4+1, 5+1, 6+1 and 7+1 seats)) may not pick up or drop off passengers at the Benovo location.'],
            ['group' => 'panel', 'key' => 'limo_info_benovo_ban', 'locale' => 'cg', 'text' => 'Limo vozilima (Putničko vozilo (4+1, 5+1, 6+1 i 7+1 mjesta)) nije dozvoljen ukrcaj niti iskrcaj putnika na lokaciji Benovo.'],
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
            ->where('group', 'panel')
            ->where('key', 'limo_info_pickup_place_2')
            ->where('locale', 'en')
            ->update(['text' => 'exit from Riva parking area, across from the market', 'updated_at' => now()]);

        DB::table('ui_translations')
            ->where('group', 'panel')
            ->where('key', 'limo_info_pickup_place_2')
            ->where('locale', 'cg')
            ->update(['text' => 'izlaz iz parking prostora Riva, preko puta pijace', 'updated_at' => now()]);

        DB::table('ui_translations')
            ->where('group', 'panel')
            ->where('key', 'limo_info_benovo_ban')
            ->where('locale', 'en')
            ->update(['text' => 'Limo vehicles may not pick up or drop off passengers at the Benovo location.', 'updated_at' => now()]);

        DB::table('ui_translations')
            ->where('group', 'panel')
            ->where('key', 'limo_info_benovo_ban')
            ->where('locale', 'cg')
            ->update(['text' => 'Limo vozilima nije dozvoljen ukrcaj niti iskrcaj putnika na lokaciji Benovo.', 'updated_at' => now()]);
    }
};
