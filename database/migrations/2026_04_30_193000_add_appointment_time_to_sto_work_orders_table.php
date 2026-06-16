<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sto_work_orders', function (Blueprint $table) {
            $table->time('appointment_time')->nullable()->after('opened_at');
        });
    }

    public function down(): void
    {
        Schema::table('sto_work_orders', function (Blueprint $table) {
            $table->dropColumn('appointment_time');
        });
    }
};
