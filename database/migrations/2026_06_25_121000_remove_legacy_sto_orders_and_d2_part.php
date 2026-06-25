<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Intentionally no-op for live: local cleanup of legacy STO orders and D2-0001
        // was done manually during audit, but production data must be preserved.
    }

    public function down(): void
    {
        // No-op.
    }
};
