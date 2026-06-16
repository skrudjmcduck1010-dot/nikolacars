<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['part_catalog_categories', 'part_catalog_items'] as $table) {
            DB::table($table)
                ->whereNotNull('model_label')
                ->orderBy('id')
                ->select(['id', 'model_label'])
                ->chunkById(200, function ($rows) use ($table): void {
                    foreach ($rows as $row) {
                        [$modelName, $yearFrom, $yearTo] = $this->modelYears($row->model_label);

                        DB::table($table)
                            ->where('id', $row->id)
                            ->update([
                                'model_name' => $modelName,
                                'year_from' => $yearFrom,
                                'year_to' => $yearTo,
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        //
    }

    protected function modelYears(?string $label): array
    {
        if ($label === null) {
            return [null, null, null];
        }

        $modelName = trim((string) preg_replace('/\s+\\d{2}\\.\\d{4}.*$/u', '', $label));
        $modelName = trim((string) preg_replace('/\s+\\d{4}\s*-\s*\\d{4}.*$/u', '', $modelName));
        $yearFrom = null;
        $yearTo = null;

        if (preg_match('/(\\d{2})\\.(\\d{4})\s*-\s*(?:(\\d{2})\\.(\\d{4}))?/u', $label, $matches) === 1) {
            $yearFrom = (int) $matches[2];
            $yearTo = isset($matches[4]) && $matches[4] !== '' ? (int) $matches[4] : null;
        } elseif (preg_match('/(\\d{4})\s*-\s*(\\d{4})?/u', $label, $matches) === 1) {
            $yearFrom = (int) $matches[1];
            $yearTo = isset($matches[2]) && $matches[2] !== '' ? (int) $matches[2] : null;
        }

        return [$modelName, $yearFrom, $yearTo];
    }
};
