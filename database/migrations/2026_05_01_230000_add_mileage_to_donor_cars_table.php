<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'mileage')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->unsignedInteger('mileage')->nullable()->after('color');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'mileage')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->dropColumn('mileage');
            });
        }
    }
};
