<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const STO_NIKOLACARS_NAME = "\u{0421}\u{0422}\u{041E} NikolaCars";

    public function up(): void
    {
        if (! Schema::hasTable('counterparties') || ! Schema::hasTable('customer_orders')) {
            return;
        }

        DB::transaction(function (): void {
            $stoCounterpartyId = $this->stoCounterpartyId();

            DB::table('customer_orders')
                ->where('delivery_method', 'sto')
                ->update($this->stoCustomerOrderPayload($stoCounterpartyId));
        });
    }

    public function down(): void
    {
        //
    }

    private function stoCounterpartyId(): int
    {
        $row = DB::table('counterparties')
            ->where('name', self::STO_NIKOLACARS_NAME)
            ->orderBy('id')
            ->first();

        if (! $row) {
            return (int) DB::table('counterparties')->insertGetId($this->stoCounterpartyPayload());
        }

        DB::table('counterparties')
            ->where('id', $row->id)
            ->update($this->stoCounterpartyPayload(false));

        return (int) $row->id;
    }

    private function stoCounterpartyPayload(bool $includeCreatedAt = true): array
    {
        $payload = [
            'type' => 'parts',
            'name' => self::STO_NIKOLACARS_NAME,
            'phone' => null,
            'is_active' => true,
        ];

        if ($includeCreatedAt && Schema::hasColumn('counterparties', 'created_at')) {
            $payload['created_at'] = now();
        }

        if (Schema::hasColumn('counterparties', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        return $payload;
    }

    private function stoCustomerOrderPayload(int $stoCounterpartyId): array
    {
        $payload = [
            'counterparty_id' => $stoCounterpartyId,
            'client_phone' => null,
            'client_first_name' => "\u{0421}\u{0422}\u{041E}",
            'client_last_name' => null,
        ];

        if (Schema::hasColumn('customer_orders', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        return $payload;
    }
};
