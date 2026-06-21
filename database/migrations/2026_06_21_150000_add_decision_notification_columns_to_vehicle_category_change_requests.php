<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('vehicle_category_change_requests', function (Blueprint $table) {
            $table->text('rejection_reason')->nullable()->after('reviewed_at');
            $table->timestamp('approved_notification_sent_at')->nullable()->after('rejection_reason');
            $table->timestamp('rejected_notification_sent_at')->nullable()->after('approved_notification_sent_at');
        });
    }

    public function down(): void
    {
        Schema::table('vehicle_category_change_requests', function (Blueprint $table) {
            $table->dropColumn([
                'rejection_reason',
                'approved_notification_sent_at',
                'rejected_notification_sent_at',
            ]);
        });
    }
};
