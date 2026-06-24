<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_order_shipments', function (Blueprint $table): void {
            $table->string('np_return_tracking_number')->nullable()->after('np_status_detail')->index();
            $table->string('np_return_document_type')->nullable()->after('np_return_tracking_number');
            $table->timestamp('np_return_created_at')->nullable()->after('np_return_document_type');
        });
    }

    public function down(): void
    {
        Schema::table('customer_order_shipments', function (Blueprint $table): void {
            $table->dropColumn([
                'np_return_tracking_number',
                'np_return_document_type',
                'np_return_created_at',
            ]);
        });
    }
};
