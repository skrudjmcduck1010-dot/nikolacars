<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'paint_code')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->string('paint_code')->nullable()->after('color');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'paint_code')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->dropColumn('paint_code');
            });
        }
    }
};
