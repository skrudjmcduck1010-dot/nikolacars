<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_order_shipments', function (Blueprint $table): void {
            $table->string('np_return_status_code')->nullable()->after('np_return_created_at')->index();
            $table->text('np_return_status')->nullable()->after('np_return_status_code');
            $table->text('np_return_status_detail')->nullable()->after('np_return_status');
            $table->timestamp('np_return_status_checked_at')->nullable()->after('np_return_status_detail');
        });
    }

    public function down(): void
    {
        Schema::table('customer_order_shipments', function (Blueprint $table): void {
            $table->dropColumn([
                'np_return_status_code',
                'np_return_status',
                'np_return_status_detail',
                'np_return_status_checked_at',
            ]);
        });
    }
};
