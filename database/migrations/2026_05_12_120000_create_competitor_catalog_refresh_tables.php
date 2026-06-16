<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competitor_catalog_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source')->index();
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('progress_current')->default(0);
            $table->unsignedInteger('progress_total')->default(0);
            $table->string('message')->nullable();
            $table->json('stats')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable()->index();
            $table->timestamp('finished_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('is_competitor_part')->default(false)->after('is_auto_generated')->index();
            $table->string('competitor_source')->nullable()->after('is_competitor_part')->index();
            $table->string('competitor_source_url')->nullable()->after('competitor_source');
            $table->boolean('competitor_available')->default(true)->after('competitor_source_url')->index();
            $table->timestamp('competitor_last_seen_at')->nullable()->after('competitor_available')->index();
        });

        Schema::create('product_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_catalog_item_id')->constrained()->cascadeOnDelete();
            $table->string('source')->nullable()->index();
            $table->decimal('old_price', 12, 2)->nullable();
            $table->decimal('new_price', 12, 2)->nullable();
            $table->string('currency', 3)->default('USD');
            $table->timestamp('changed_at')->useCurrent()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_price_histories');

        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn([
                'is_competitor_part',
                'competitor_source',
                'competitor_source_url',
                'competitor_available',
                'competitor_last_seen_at',
            ]);
        });

        Schema::dropIfExists('competitor_catalog_runs');
    }
};
