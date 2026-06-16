<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'purchase_date')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->date('purchase_date')->nullable()->after('color');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'purchase_date')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->dropColumn('purchase_date');
            });
        }
    }
};
