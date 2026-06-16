<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sto_work_orders', function (Blueprint $table) {
            $table->dateTime('work_started_at')->nullable()->after('opened_at')->index();
        });

        DB::table('sto_work_orders')
            ->whereNull('work_started_at')
            ->whereIn('status', ['in_work', 'waiting_parts', 'paused', 'completed', 'paid'])
            ->update([
                'work_started_at' => DB::raw('COALESCE(completed_at, opened_at, created_at)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('sto_work_orders', function (Blueprint $table) {
            $table->dropIndex(['work_started_at']);
            $table->dropColumn('work_started_at');
        });
    }
};
