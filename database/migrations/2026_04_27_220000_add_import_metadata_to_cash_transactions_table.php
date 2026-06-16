<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->string('source_sheet')->nullable()->after('source')->index();
            $table->decimal('exchange_rate', 8, 2)->nullable()->after('source_sheet');
        });
    }

    public function down(): void
    {
        Schema::table('cash_transactions', function (Blueprint $table) {
            $table->dropColumn(['source_sheet', 'exchange_rate']);
        });
    }
};
