<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('tax_id', 30)->nullable()->change();
        });
    }

    public function down(): void
    {
        if (DB::table('suppliers')->whereNull('tax_id')->exists()) {
            throw new RuntimeException('No se puede revertir tax_id a obligatorio mientras existan proveedores sin identificador tributario.');
        }

        Schema::table('suppliers', function (Blueprint $table): void {
            $table->string('tax_id', 30)->nullable(false)->change();
        });
    }
};
