<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('warehouses', 'code')) {
            return;
        }

        Schema::table('warehouses', function (Blueprint $table) {
            $table->dropUnique('warehouses_code_unique');
            $table->dropColumn('code');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('warehouses', 'code')) {
            return;
        }

        Schema::table('warehouses', function (Blueprint $table) {
            $table->string('code')->nullable()->unique();
        });
    }
};
