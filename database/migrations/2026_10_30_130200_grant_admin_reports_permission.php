<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            DB::table('permissions')->insertOrIgnore([
                'code' => 'reports.view', 'name' => 'Consultar reportes', 'group' => 'reports',
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $permissionId = DB::table('permissions')->where('code', 'reports.view')->value('id');
            $adminRoleId = DB::table('roles')->where('code', 'admin')->value('id');
            if ($adminRoleId !== null) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $adminRoleId, 'permission_id' => $permissionId,
                ]);
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Migración de permisos de reportes protegida: la retirada requiere revisión explícita.');
    }
};
