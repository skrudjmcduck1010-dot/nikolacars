<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('valera_cashbook_transfers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('cash_transaction_id')->unique()->constrained('cash_transactions')->cascadeOnDelete();
            $table->string('status')->default('pending')->index();
            $table->foreignId('confirmed_valera_cash_transaction_id')->nullable();
            $table->foreign('confirmed_valera_cash_transaction_id', 'vcbt_confirmed_valera_tx_fk')
                ->references('id')
                ->on('valera_cash_transactions')
                ->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamps();
        });

        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => 'Инкассо Валера'],
            [
                'operation_type' => 'expense',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('cashbook_labels')->updateOrInsert(
            ['name' => 'Инкассо Женя'],
            [
                'operation_type' => 'income',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );

        DB::table('cash_transactions')
            ->whereRaw("TRIM(COALESCE(label, '')) = ?", ['Инкассо Валера'])
            ->orderBy('id')
            ->get(['id'])
            ->each(function (object $row): void {
                DB::table('valera_cashbook_transfers')->insertOrIgnore([
                    'cash_transaction_id' => $row->id,
                    'status' => 'pending',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('valera_cashbook_transfers');
    }
};
