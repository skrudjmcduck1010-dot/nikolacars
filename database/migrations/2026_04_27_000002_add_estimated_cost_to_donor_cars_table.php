<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'estimated_cost_usd')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->decimal('estimated_cost_usd', 12, 2)->nullable()->after('color');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'estimated_cost_usd')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->dropColumn('estimated_cost_usd');
            });
        }
    }
};
