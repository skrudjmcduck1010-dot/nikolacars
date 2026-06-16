<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('product_price_histories')
            || Schema::hasColumn('product_price_histories', 'part_catalog_item_id')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('alter table product_price_histories add column part_catalog_item_id bigint unsigned null after product_id');
            DB::statement('
                update product_price_histories pph
                join products p on p.id = pph.product_id
                set pph.part_catalog_item_id = p.source_part_catalog_item_id
            ');
            DB::statement('delete from product_price_histories where part_catalog_item_id is null');
            DB::statement('alter table product_price_histories drop foreign key product_price_histories_product_id_foreign');
            DB::statement('alter table product_price_histories drop column product_id');
            DB::statement('alter table product_price_histories modify part_catalog_item_id bigint unsigned not null');
            DB::statement('alter table product_price_histories add constraint product_price_histories_part_catalog_item_id_foreign foreign key (part_catalog_item_id) references part_catalog_items(id) on delete cascade');

            return;
        }

        Schema::table('product_price_histories', function ($table): void {
            $table->foreignId('part_catalog_item_id')->nullable()->after('product_id')->constrained()->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('product_price_histories')
            || ! Schema::hasColumn('product_price_histories', 'part_catalog_item_id')) {
            return;
        }

        $driver = DB::connection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement('alter table product_price_histories add column product_id bigint unsigned null after id');
            DB::statement('alter table product_price_histories drop foreign key product_price_histories_part_catalog_item_id_foreign');
            DB::statement('alter table product_price_histories drop column part_catalog_item_id');

            return;
        }

        Schema::table('product_price_histories', function ($table): void {
            $table->dropConstrainedForeignId('part_catalog_item_id');
        });
    }
};
