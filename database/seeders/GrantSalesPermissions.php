<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

DB::transaction(function (): void {
    DB::select('select pg_advisory_xact_lock(74126001)');

    $definitions = [
        'sales.view' => 'Consultar ventas',
        'sales.create' => 'Crear ventas',
        'sales.update' => 'Editar ventas',
        'sales.confirm' => 'Confirmar ventas',
    ];

    foreach ($definitions as $code => $name) {
        $permission = Permission::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'group' => 'sales']
        );

        if ($permission->group !== 'sales') {
            throw new RuntimeException(
                "El permiso {$code} ya existe con un grupo inesperado."
            );
        }
    }

    $admin = Role::query()->where('code', 'admin')->firstOrFail();
    $seller = Role::query()->where('code', 'seller')->firstOrFail();

    $permissionIds = Permission::query()
        ->whereIn('code', array_keys($definitions))
        ->pluck('id', 'code');

    if ($permissionIds->count() !== count($definitions)) {
        throw new RuntimeException('No se encontraron todos los permisos de ventas.');
    }

    $admin->permissions()->syncWithoutDetaching($permissionIds->values()->all());

    $sellerCodes = [
        'sales.view',
        'sales.create',
        'sales.update',
        'sales.confirm',
    ];

    $seller->permissions()->syncWithoutDetaching(
        $permissionIds->only($sellerCodes)->values()->all()
    );
});
