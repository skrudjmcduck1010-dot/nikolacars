<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashbook_labels', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->unique();
            $table->timestamps();
        });

        DB::table('cashbook_labels')->insertUsing(
            ['name', 'created_at', 'updated_at'],
            DB::table('cash_transactions')
                ->select('label')
                ->selectRaw('CURRENT_TIMESTAMP')
                ->selectRaw('CURRENT_TIMESTAMP')
                ->whereNotNull('label')
                ->where('label', '<>', '')
                ->distinct()
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('cashbook_labels');
    }
};
