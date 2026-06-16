<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sto_work_orders')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE sto_work_orders MODIFY work_started_at DATETIME NULL');
            DB::statement('ALTER TABLE sto_work_orders MODIFY completed_at DATETIME NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sto_work_orders ALTER COLUMN work_started_at TYPE timestamp(0) without time zone USING work_started_at::timestamp(0) without time zone');
            DB::statement('ALTER TABLE sto_work_orders ALTER COLUMN completed_at TYPE timestamp(0) without time zone USING completed_at::timestamp(0) without time zone');
        }

        if ($driver === 'sqlite') {
            DB::table('sto_work_orders')
                ->whereNotNull('work_started_at')
                ->whereRaw('LENGTH(work_started_at) = 10')
                ->update([
                    'work_started_at' => DB::raw("work_started_at || ' 00:00:00'"),
                ]);

            DB::table('sto_work_orders')
                ->whereNotNull('completed_at')
                ->whereRaw('LENGTH(completed_at) = 10')
                ->update([
                    'completed_at' => DB::raw("completed_at || ' 00:00:00'"),
                ]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('sto_work_orders')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE sto_work_orders MODIFY work_started_at DATE NULL');
            DB::statement('ALTER TABLE sto_work_orders MODIFY completed_at DATE NULL');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE sto_work_orders ALTER COLUMN work_started_at TYPE date USING work_started_at::date');
            DB::statement('ALTER TABLE sto_work_orders ALTER COLUMN completed_at TYPE date USING completed_at::date');
        }
    }
};
