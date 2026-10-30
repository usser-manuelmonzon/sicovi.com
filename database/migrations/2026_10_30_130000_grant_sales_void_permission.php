<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('permissions')->insertOrIgnore([
                'code' => 'sales.void',
                'name' => 'Anular ventas',
                'group' => 'sales',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $permissionId = DB::table('permissions')->where('code', 'sales.void')->value('id');
            $roleIds = DB::table('roles')->whereIn('code', ['admin', 'seller'])->pluck('id');
            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migración de permiso protegida: retirar permisos exige una revisión explícita de asignaciones.');
    }
};
