<?php
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;

DB::transaction(function (): void {
    DB::select('select pg_advisory_xact_lock(74126001)');

    $definitions = [
        'customers.view' => 'Consultar clientes',
        'customers.create' => 'Crear clientes',
        'customers.update' => 'Editar clientes',
        'customers.deactivate' => 'Desactivar clientes',
    ];

    foreach ($definitions as $code => $name) {
        Permission::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $name, 'group' => 'customers']
        );
    }

    $admin = Role::query()
        ->where('code', 'admin')
        ->where('active', true)
        ->firstOrFail();

    $permissionIds = Permission::query()
        ->whereIn('code', array_keys($definitions))
        ->pluck('id')
        ->all();

    $admin->permissions()->syncWithoutDetaching($permissionIds);
});
