<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'status')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->string('status')->default('in_transit')->after('vin');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'status')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->dropColumn('status');
            });
        }
    }
};
