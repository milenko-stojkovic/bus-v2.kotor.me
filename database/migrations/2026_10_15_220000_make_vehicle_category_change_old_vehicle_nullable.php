<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->dropOldVehicleForeignKey();

        Schema::table('vehicle_category_change_requests', function (Blueprint $table) {
            $table->unsignedInteger('old_vehicle_id')->nullable()->change();
        });

        $this->addOldVehicleForeignKey();
    }

    public function down(): void
    {
        $this->dropOldVehicleForeignKey();

        Schema::table('vehicle_category_change_requests', function (Blueprint $table) {
            $table->unsignedInteger('old_vehicle_id')->nullable(false)->change();
        });

        $this->addOldVehicleForeignKey();
    }

    private function dropOldVehicleForeignKey(): void
    {
        if (! $this->usesNamedForeignKeys()) {
            return;
        }

        Schema::table('vehicle_category_change_requests', function (Blueprint $table) {
            $table->dropForeign('fk_vccr_old_vehicle');
        });
    }

    private function addOldVehicleForeignKey(): void
    {
        if (! $this->usesNamedForeignKeys()) {
            return;
        }

        Schema::table('vehicle_category_change_requests', function (Blueprint $table) {
            $table->foreign('old_vehicle_id', 'fk_vccr_old_vehicle')
                ->references('id')
                ->on('vehicles')
                ->restrictOnDelete();
        });
    }

    private function usesNamedForeignKeys(): bool
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
    }
};
