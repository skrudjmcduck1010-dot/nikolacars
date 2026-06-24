<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_order_shipments', function (Blueprint $table): void {
            $table->text('np_status_detail')->nullable()->after('np_status');
        });
    }

    public function down(): void
    {
        Schema::table('customer_order_shipments', function (Blueprint $table): void {
            $table->dropColumn('np_status_detail');
        });
    }
};
