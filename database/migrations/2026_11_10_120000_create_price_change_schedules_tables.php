<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('price_change_schedules', function (Blueprint $table) {
            $table->id();
            $table->date('effective_on');
            $table->string('status', 20);
            // 'pending' while scheduled; NULL after apply or cancel. Unique allows many NULLs.
            $table->string('pending_slot', 16)->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->unsignedBigInteger('created_by_admin_id')->nullable();
            $table->unsignedBigInteger('updated_by_admin_id')->nullable();
            $table->timestamps();

            $table->unique('pending_slot');
            $table->index(['status', 'effective_on']);

            $table->foreign('created_by_admin_id')
                ->references('id')
                ->on('admins')
                ->nullOnDelete();
            $table->foreign('updated_by_admin_id')
                ->references('id')
                ->on('admins')
                ->nullOnDelete();
        });

        Schema::create('price_change_schedule_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('price_change_schedule_id')
                ->constrained('price_change_schedules')
                ->cascadeOnDelete();
            $table->unsignedInteger('vehicle_type_id');
            $table->decimal('price_before', 10, 2);
            $table->decimal('price_after', 10, 2);
            $table->timestamps();

            $table->unique(['price_change_schedule_id', 'vehicle_type_id'], 'price_change_lines_schedule_type_unique');
            $table->foreign('vehicle_type_id')
                ->references('id')
                ->on('vehicle_types');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('price_change_schedule_lines');
        Schema::dropIfExists('price_change_schedules');
    }
};
