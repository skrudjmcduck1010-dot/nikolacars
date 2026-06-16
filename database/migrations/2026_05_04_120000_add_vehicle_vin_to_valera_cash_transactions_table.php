<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('valera_cash_transactions', function (Blueprint $table): void {
            $table->string('vehicle_vin')->nullable()->after('purpose')->index();
        });
    }

    public function down(): void
    {
        Schema::table('valera_cash_transactions', function (Blueprint $table): void {
            $table->dropColumn('vehicle_vin');
        });
    }
};
