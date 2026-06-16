<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_orders', function (Blueprint $table): void {
            if (! Schema::hasColumn('customer_orders', 'delivery_method')) {
                $table->string('delivery_method')->nullable()->after('client_last_name');
            }
        });
    }

    public function down(): void
    {
        Schema::table('customer_orders', function (Blueprint $table): void {
            if (Schema::hasColumn('customer_orders', 'delivery_method')) {
                $table->dropColumn('delivery_method');
            }
        });
    }
};
