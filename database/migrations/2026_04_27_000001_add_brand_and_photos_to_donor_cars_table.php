<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'brand')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->string('brand')->default('Tesla')->after('vin');
            });
        }

        if (! Schema::hasColumn('donor_cars', 'photos')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->json('photos')->nullable()->after('notes');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'photos')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->dropColumn('photos');
            });
        }

        if (Schema::hasColumn('donor_cars', 'brand')) {
            Schema::table('donor_cars', function (Blueprint $table) {
                $table->dropColumn('brand');
            });
        }
    }
};
