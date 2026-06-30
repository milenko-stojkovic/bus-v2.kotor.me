<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('temp_data', function (Blueprint $table) {
            if (! Schema::hasColumn('temp_data', 'payment_redirect_url')) {
                $table->string('payment_redirect_url', 2048)->nullable()->after('retry_token');
            }
        });
    }

    public function down(): void
    {
        Schema::table('temp_data', function (Blueprint $table) {
            if (Schema::hasColumn('temp_data', 'payment_redirect_url')) {
                $table->dropColumn('payment_redirect_url');
            }
        });
    }
};
