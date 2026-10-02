<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('block_zone_worklist', function (Blueprint $table) {
            $table->unsignedBigInteger('reviewed_by_admin_id')->nullable()->after('temp_data_id');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by_admin_id');
            $table->string('resolution_note', 500)->nullable()->after('reviewed_at');

            $table->foreign('reviewed_by_admin_id', 'fk_bzw_reviewed_admin')
                ->references('id')
                ->on('admins')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('block_zone_worklist', function (Blueprint $table) {
            $table->dropForeign('fk_bzw_reviewed_admin');
            $table->dropColumn(['reviewed_by_admin_id', 'reviewed_at', 'resolution_note']);
        });
    }
};
