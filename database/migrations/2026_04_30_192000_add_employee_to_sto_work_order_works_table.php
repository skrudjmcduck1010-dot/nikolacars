<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sto_work_order_works', function (Blueprint $table) {
            $table->foreignId('sto_employee_id')
                ->nullable()
                ->after('sto_work_order_id')
                ->constrained('sto_employees')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sto_work_order_works', function (Blueprint $table) {
            $table->dropConstrainedForeignId('sto_employee_id');
        });
    }
};
