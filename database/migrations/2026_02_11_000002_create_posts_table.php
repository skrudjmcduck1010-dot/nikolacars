<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('wp_id')->nullable()->index();
            $table->string('path')->unique();
            $table->string('slug')->nullable();
            $table->string('category_slug')->nullable();
            $table->timestamp('published_at')->nullable();

            $table->text('title_uk')->nullable();
            $table->longText('content_uk')->nullable();
            $table->text('meta_title_uk')->nullable();
            $table->text('meta_desc_uk')->nullable();
            $table->text('canonical_uk')->nullable();
            $table->longText('schema_uk')->nullable();

            $table->text('title_ru')->nullable();
            $table->longText('content_ru')->nullable();
            $table->text('meta_title_ru')->nullable();
            $table->text('meta_desc_ru')->nullable();
            $table->text('canonical_ru')->nullable();
            $table->longText('schema_ru')->nullable();

            $table->boolean('noindex')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('posts');
    }
};
