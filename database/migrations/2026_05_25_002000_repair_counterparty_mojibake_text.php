<?php

use App\Support\CatalogTextEncoding;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->repairTable('counterparties', [
                'name',
                'address',
                'notes',
                'car_model',
                'license_plate',
            ]);

            if (Schema::hasTable('counterparty_vehicles')) {
                $this->repairTable('counterparty_vehicles', [
                    'car_model',
                    'license_plate',
                ]);
            }
        });
    }

    public function down(): void
    {
        // Data repair is intentionally one-way.
    }

    private function repairTable(string $table, array $columns): void
    {
        $columns = collect($columns)
            ->filter(fn (string $column): bool => Schema::hasColumn($table, $column))
            ->values()
            ->all();

        if ($columns === []) {
            return;
        }

        DB::table($table)
            ->select(['id', ...$columns])
            ->orderBy('id')
            ->get()
            ->each(function (object $row) use ($table, $columns): void {
                $updates = [];

                foreach ($columns as $column) {
                    $value = $row->{$column};

                    if (! is_string($value) || $value === '') {
                        continue;
                    }

                    $fixed = CatalogTextEncoding::repair($value);

                    if ($fixed !== $value) {
                        $updates[$column] = $fixed;
                    }
                }

                if ($updates === []) {
                    return;
                }

                if (Schema::hasColumn($table, 'updated_at')) {
                    $updates['updated_at'] = now();
                }

                DB::table($table)
                    ->where('id', $row->id)
                    ->update($updates);
            });
    }
};
