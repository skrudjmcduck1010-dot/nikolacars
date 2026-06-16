<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('counterparty_vehicles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('counterparty_id')->constrained()->cascadeOnDelete();
            $table->string('car_model');
            $table->unsignedSmallInteger('car_year');
            $table->string('drive_type');
            $table->string('vin');
            $table->string('license_plate');
            $table->timestamps();

            $table->index('vin');
            $table->index('license_plate');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('counterparty_vehicles');
    }
};
