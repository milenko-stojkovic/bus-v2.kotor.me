<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservation_attributions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('reservation_id');
            // Equals reservation_id while this attribution is active. Null after reversal.
            // Multiple nulls are allowed, so history can contain several reversed rows.
            $table->unsignedBigInteger('active_reservation_id')->nullable()->unique();
            $table->unsignedBigInteger('agency_user_id');
            $table->unsignedBigInteger('previous_user_id')->nullable();
            $table->string('evidence', 500);
            $table->timestamp('attributed_at');
            $table->unsignedBigInteger('attributed_by_admin_id')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reversed_by_admin_id')->nullable();
            $table->string('reversal_evidence', 500)->nullable();
            $table->timestamps();

            $table->index('reservation_id');
            $table->index('agency_user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservation_attributions');
    }
};
