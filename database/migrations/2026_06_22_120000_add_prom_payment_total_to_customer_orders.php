<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_orders', 'paid_prom_uah')) {
                $table->decimal('paid_prom_uah', 14, 2)->default(0)->after('paid_bank_fop_uah');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('customer_orders', 'paid_prom_uah')) {
                $table->dropColumn('paid_prom_uah');
            }
        });
    }
};
