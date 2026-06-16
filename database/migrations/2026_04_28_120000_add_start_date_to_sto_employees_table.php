<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sto_employees', function (Blueprint $table) {
            $table->date('start_date')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('sto_employees', function (Blueprint $table) {
            $table->dropColumn('start_date');
        });
    }
};
