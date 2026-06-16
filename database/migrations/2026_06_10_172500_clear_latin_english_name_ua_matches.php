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

        DB::table('part_catalog_items')
            ->select(['id', 'name', 'name_en', 'name_ua'])
            ->whereNotNull('name_ua')
            ->where('name_ua', '!=', '')
            ->orderBy('id')
            ->chunkById(500, function ($items): void {
                foreach ($items as $item) {
                    $nameUa = trim((string) $item->name_ua);

                    if (! $this->shouldClearNameUa($nameUa, $item->name_en, $item->name)) {
                        continue;
                    }

                    DB::table('part_catalog_items')
                        ->where('id', $item->id)
                        ->update([
                            'name_ua' => null,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        //
    }

    private function shouldClearNameUa(string $nameUa, mixed $nameEn, mixed $name): bool
    {
        if ($nameUa === '' || ! $this->isLatinOnlyName($nameUa)) {
            return false;
        }

        foreach ([$nameEn, $name] as $englishName) {
            if ($nameUa === trim((string) $englishName)) {
                return true;
            }
        }

        return false;
    }

    private function isLatinOnlyName(string $value): bool
    {
        return preg_match('/[A-Za-z]/', $value) === 1
            && preg_match('/\p{Cyrillic}/u', $value) !== 1;
    }
};
