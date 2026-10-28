<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->char('applied_idempotency_hash', 64)->nullable()->after('applied_idempotency_key');
        });
    }

    public function down(): void
    {
        Schema::table('inventory_adjustments', function (Blueprint $table): void {
            $table->dropColumn('applied_idempotency_hash');
        });
    }
};
