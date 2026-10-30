<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $permissionId = DB::table('permissions')->where('code', 'purchases.void')->value('id');
            $purchasingRoleId = DB::table('roles')->where('code', 'purchasing')->value('id');
            if ($permissionId !== null && $purchasingRoleId !== null) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $purchasingRoleId, 'permission_id' => $permissionId,
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migración de permisos de compras protegida: la retirada requiere revisión explícita.');
    }
};
