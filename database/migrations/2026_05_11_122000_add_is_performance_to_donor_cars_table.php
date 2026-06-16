<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'is_performance')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->boolean('is_performance')->nullable()->after('battery_type')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'is_performance')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->dropColumn('is_performance');
            });
        }
    }
};
