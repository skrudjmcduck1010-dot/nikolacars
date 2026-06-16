<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('part_catalog_items')) {
            return;
        }

        $now = now()->toIso8601String();
        $hasUpdatedAt = Schema::hasColumn('part_catalog_items', 'updated_at');

        DB::table('part_catalog_items')
            ->where('source', 'nikolacars')
            ->select(['id', 'raw_attributes'])
            ->orderBy('id')
            ->chunkById(500, function ($items) use ($now, $hasUpdatedAt): void {
                foreach ($items as $item) {
                    $rawAttributes = json_decode((string) $item->raw_attributes, true);
                    $rawAttributes = is_array($rawAttributes) ? $rawAttributes : [];
                    $code = strtoupper(trim((string) ($rawAttributes['code'] ?? '')));

                    if (! str_starts_with($code, 'DON') && ! str_starts_with($code, 'RON')) {
                        continue;
                    }

                    $rawAttributes['donor_damage_checked_at'] = $now;
                    $payload = [
                        'raw_attributes' => json_encode($rawAttributes, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    ];

                    if ($hasUpdatedAt) {
                        $payload['updated_at'] = now();
                    }

                    DB::table('part_catalog_items')
                        ->where('id', $item->id)
                        ->update($payload);
                }
            });
    }

    public function down(): void
    {
        //
    }
};
