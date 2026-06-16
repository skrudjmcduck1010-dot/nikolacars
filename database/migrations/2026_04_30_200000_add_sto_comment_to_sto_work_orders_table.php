<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sto_work_orders', function (Blueprint $table) {
            $table->text('sto_comment')->nullable()->after('parts_note');
        });
    }

    public function down(): void
    {
        Schema::table('sto_work_orders', function (Blueprint $table) {
            $table->dropColumn('sto_comment');
        });
    }
};
