<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('part_catalog_categories', function (Blueprint $table) {
            if (! Schema::hasColumn('part_catalog_categories', 'preview_image_url')) {
                $table->string('preview_image_url')->nullable()->after('source_url');
            }
        });

        foreach ($this->modelPreviewImages() as $sourceUrl => $imageUrl) {
            DB::table('part_catalog_categories')
                ->where('source_url', $sourceUrl)
                ->update(['preview_image_url' => $imageUrl]);
        }
    }

    public function down(): void
    {
        Schema::table('part_catalog_categories', function (Blueprint $table) {
            if (Schema::hasColumn('part_catalog_categories', 'preview_image_url')) {
                $table->dropColumn('preview_image_url');
            }
        });
    }

    private function modelPreviewImages(): array
    {
        return [
            'https://tcarservice.com/zapchasty/model-s-321' => 'https://tcarservice.com/storage/editor/fotos/530x0/f44e47113dfec8631ed0c55d60e910c7_1713955563.webp',
            'https://tcarservice.com/zapchasty/model-s2-322' => 'https://tcarservice.com/storage/editor/fotos/530x0/86776dd9c9d8c52b2eebbbb8b4d060ba_1713958154.webp',
            'https://tcarservice.com/zapchasty/model-s-plaid-323' => 'https://tcarservice.com/storage/editor/fotos/530x0/e1c438d32ca54fddd1f49b628e5117c6_1713954573.webp',
            'https://tcarservice.com/zapchasty/model-x-324' => 'https://tcarservice.com/storage/editor/fotos/530x0/2b062b5d710e6dd9d54fbed643d74840_1713958401.webp',
            'https://tcarservice.com/zapchasty/model-x-plaid-325' => 'https://tcarservice.com/storage/editor/fotos/530x0/7051f412da109ca7841207fce7ff8ba5_1713959002.webp',
            'https://tcarservice.com/zapchasty/model-y-327' => 'https://tcarservice.com/storage/editor/fotos/530x0/42da0e97dd99831fb6d56a1ac8c7dff2_1713959189.webp',
            'https://tcarservice.com/zapchasty/model-y-juniper-2684' => 'https://tcarservice.com/storage/editor/fotos/530x0/afcae58ec18c6a009ab6c1d5901310d3_1745331939.webp',
            'https://tcarservice.com/zapchasty/model-3-326' => 'https://tcarservice.com/storage/editor/fotos/530x0/996a68040be17e96ac4a367d3fd16f32_1713959319.webp',
            'https://tcarservice.com/zapchasty/model-3highland-1587' => 'https://tcarservice.com/storage/editor/fotos/530x0/9f59852d084a40813ae8771350e544c4_1713960938.webp',
        ];
    }
};
