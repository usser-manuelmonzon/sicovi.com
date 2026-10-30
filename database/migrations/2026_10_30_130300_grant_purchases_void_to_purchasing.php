<?php

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // purchases.void predates this migration. An absent pivot may mean
        // an intentional revocation, so existing assignments require review
        // and an explicit change in Administración > Roles.
        // SystemSeeder grants this permission only when creating a new role.
    }

    public function down(): void
    {
        throw new RuntimeException('Migración de permisos de compras protegida: la retirada requiere revisión explícita.');
    }
};
