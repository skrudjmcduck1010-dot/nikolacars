<?php

use App\Support\TextEncodingNormalizer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('donor_cars')) {
            return;
        }

        $columns = collect(['vin', 'brand', 'model', 'color', 'notes'])
            ->filter(fn (string $column): bool => Schema::hasColumn('donor_cars', $column))
            ->values()
            ->all();

        if ($columns === []) {
            return;
        }

        DB::table('donor_cars')
            ->select(['id', ...$columns])
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($columns): void {
                foreach ($rows as $row) {
                    $updates = [];

                    foreach ($columns as $column) {
                        $value = $row->{$column};

                        if (! is_string($value) || $value === '') {
                            continue;
                        }

                        $fixed = TextEncodingNormalizer::normalize($value);

                        if ($fixed !== $value) {
                            $updates[$column] = $fixed;
                        }
                    }

                    if ($updates === []) {
                        continue;
                    }

                    if (Schema::hasColumn('donor_cars', 'updated_at')) {
                        $updates['updated_at'] = now();
                    }

                    DB::table('donor_cars')
                        ->where('id', $row->id)
                        ->update($updates);
                }
            });
    }

    public function down(): void
    {
        // Data repair is intentionally one-way.
    }
};
