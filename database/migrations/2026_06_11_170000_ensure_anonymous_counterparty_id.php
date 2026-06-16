<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const ANONYMOUS_ID = 1;

    private const ANONYMOUS_NAME = "\u{041D}\u{0435}\u{0438}\u{0437}\u{0432}\u{0435}\u{0441}\u{0442}\u{043D}\u{044B}\u{0439} \u{0410}\u{043D}\u{043E}\u{043D}\u{0438}\u{043C}\u{0443}\u{0441}";

    private const ANONYMOUS_PHONE = '+380000000000';

    public function up(): void
    {
        if (! Schema::hasTable('counterparties')) {
            return;
        }

        DB::transaction(function (): void {
            $idOne = DB::table('counterparties')->where('id', self::ANONYMOUS_ID)->first();

            if ($idOne && ! $this->isAnonymousCounterparty($idOne)) {
                $replacementId = $this->cloneCounterparty($idOne);
                $this->moveCounterpartyReferences(self::ANONYMOUS_ID, $replacementId);
                DB::table('counterparties')->where('id', self::ANONYMOUS_ID)->delete();
            }

            $anonymousRows = DB::table('counterparties')
                ->where('name', self::ANONYMOUS_NAME)
                ->orderByRaw('id = ? desc', [self::ANONYMOUS_ID])
                ->orderBy('id')
                ->get();

            if (! DB::table('counterparties')->where('id', self::ANONYMOUS_ID)->exists()) {
                DB::table('counterparties')->insert($this->anonymousPayload());
            }

            DB::table('counterparties')
                ->where('id', self::ANONYMOUS_ID)
                ->update($this->anonymousPayload(false));

            foreach ($anonymousRows as $row) {
                if ((int) $row->id === self::ANONYMOUS_ID) {
                    continue;
                }

                $this->moveCounterpartyReferences((int) $row->id, self::ANONYMOUS_ID);
                DB::table('counterparties')->where('id', $row->id)->delete();
            }
        });
    }

    public function down(): void
    {
        //
    }

    private function isAnonymousCounterparty(object $counterparty): bool
    {
        return (string) $counterparty->name === self::ANONYMOUS_NAME
            && preg_replace('/\D+/', '', (string) $counterparty->phone) === preg_replace('/\D+/', '', self::ANONYMOUS_PHONE);
    }

    private function cloneCounterparty(object $counterparty): int
    {
        $payload = (array) $counterparty;
        unset($payload['id']);

        if (array_key_exists('updated_at', $payload)) {
            $payload['updated_at'] = now();
        }

        return (int) DB::table('counterparties')->insertGetId($payload);
    }

    private function moveCounterpartyReferences(int $fromId, int $toId): void
    {
        foreach ([
            'counterparty_vehicles',
            'customer_orders',
            'movements',
            'purchases',
            'sto_work_orders',
        ] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'counterparty_id')) {
                DB::table($table)->where('counterparty_id', $fromId)->update(['counterparty_id' => $toId]);
            }
        }
    }

    private function anonymousPayload(bool $includeId = true): array
    {
        $payload = [
            'type' => 'parts',
            'name' => self::ANONYMOUS_NAME,
            'phone' => self::ANONYMOUS_PHONE,
            'is_active' => true,
        ];

        if ($includeId) {
            $payload = ['id' => self::ANONYMOUS_ID] + $payload;
        }

        if (Schema::hasColumn('counterparties', 'created_at') && $includeId) {
            $payload['created_at'] = now();
        }

        if (Schema::hasColumn('counterparties', 'updated_at')) {
            $payload['updated_at'] = now();
        }

        return $payload;
    }
};
