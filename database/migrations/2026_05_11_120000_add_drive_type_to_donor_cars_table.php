<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('donor_cars', 'drive_type')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->string('drive_type')->nullable()->after('model')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('donor_cars', 'drive_type')) {
            Schema::table('donor_cars', function (Blueprint $table): void {
                $table->dropColumn('drive_type');
            });
        }
    }
};
