<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('counterparties', function (Blueprint $table) {
            if (! Schema::hasColumn('counterparties', 'car_model')) {
                $table->string('car_model')->nullable()->after('notes');
            }

            if (! Schema::hasColumn('counterparties', 'car_year')) {
                $table->unsignedSmallInteger('car_year')->nullable()->after('car_model');
            }

            if (! Schema::hasColumn('counterparties', 'drive_type')) {
                $table->string('drive_type')->nullable()->after('car_year');
            }

            if (! Schema::hasColumn('counterparties', 'vin')) {
                $table->string('vin')->nullable()->after('drive_type');
            }

            if (! Schema::hasColumn('counterparties', 'license_plate')) {
                $table->string('license_plate')->nullable()->after('vin');
            }
        });
    }

    public function down(): void
    {
        Schema::table('counterparties', function (Blueprint $table) {
            foreach (['license_plate', 'vin', 'drive_type', 'car_year', 'car_model'] as $column) {
                if (Schema::hasColumn('counterparties', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
