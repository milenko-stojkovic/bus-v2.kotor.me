<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('free_reservation_requests', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('status');
            $table->unsignedBigInteger('rejected_by_admin_id')->nullable()->after('rejection_reason');
            $table->timestamp('rejected_at')->nullable()->after('rejected_by_admin_id');
            $table->timestamp('rejection_notification_sent_at')->nullable()->after('rejected_at');

            $table->foreign('rejected_by_admin_id', 'fk_frr_rejected_admin')
                ->references('id')
                ->on('admins')
                ->nullOnDelete();
        });

        $now = now();
        $rows = [
            ['group' => 'panel', 'key' => 'fzbr_rejection_reason_label', 'locale' => 'cg', 'text' => 'Razlog odbijanja'],
            ['group' => 'panel', 'key' => 'fzbr_rejection_reason_label', 'locale' => 'en', 'text' => 'Rejection reason'],
        ];

        foreach ($rows as $row) {
            DB::table('ui_translations')->updateOrInsert(
                ['group' => $row['group'], 'key' => $row['key'], 'locale' => $row['locale']],
                ['text' => $row['text'], 'updated_at' => $now, 'created_at' => $now],
            );
        }

        Cache::forget('ui_translations:group=panel:locale=cg');
        Cache::forget('ui_translations:group=panel:locale=en');
        Cache::forget('ui_translations:any:group=panel:key=fzbr_rejection_reason_label');
    }

    public function down(): void
    {
        DB::table('ui_translations')
            ->where('group', 'panel')
            ->where('key', 'fzbr_rejection_reason_label')
            ->delete();

        Cache::forget('ui_translations:group=panel:locale=cg');
        Cache::forget('ui_translations:group=panel:locale=en');
        Cache::forget('ui_translations:any:group=panel:key=fzbr_rejection_reason_label');

        Schema::table('free_reservation_requests', function (Blueprint $table) {
            $table->dropForeign('fk_frr_rejected_admin');
            $table->dropColumn([
                'rejection_reason',
                'rejected_by_admin_id',
                'rejected_at',
                'rejection_notification_sent_at',
            ]);
        });
    }
};
