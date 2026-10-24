<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SystemSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            // Shared with all changes capable of affecting administrative access.
            DB::select('select pg_advisory_xact_lock(74126001)');
            $definitions = [
                'users' => ['view' => 'Consultar usuarios', 'create' => 'Crear usuarios', 'update' => 'Editar usuarios', 'deactivate' => 'Desactivar usuarios', 'reset_password' => 'Restablecer contraseñas'],
                'roles' => ['view' => 'Consultar roles', 'manage' => 'Administrar roles y permisos'],
                'settings' => ['view' => 'Consultar configuración', 'update' => 'Editar configuración'],
                'products' => ['view' => 'Consultar productos', 'create' => 'Crear productos', 'update' => 'Editar productos', 'deactivate' => 'Desactivar productos', 'view_cost' => 'Consultar costos de referencia'],
                'categories' => ['view' => 'Consultar categorías', 'manage' => 'Administrar categorías'],
                'subcategories' => ['view' => 'Consultar subcategorías', 'manage' => 'Administrar subcategorías'],
                'brands' => ['view' => 'Consultar marcas', 'manage' => 'Administrar marcas'],
                'units' => ['view' => 'Consultar unidades', 'manage' => 'Administrar unidades'],
                'suppliers' => ['view' => 'Consultar proveedores', 'create' => 'Crear proveedores', 'update' => 'Editar proveedores', 'deactivate' => 'Desactivar proveedores'],
                'inventory' => ['view' => 'Consultar inventario', 'adjust' => 'Registrar ajustes de inventario', 'void_adjustment' => 'Anular ajustes de inventario'],
                'purchases' => ['view' => 'Consultar compras', 'create' => 'Crear compras', 'confirm' => 'Confirmar compras', 'void' => 'Anular compras'],
            ];
            $permissions = [];
            $created = [];
            foreach ($definitions as $group => $actions) {
                foreach ($actions as $action => $name) {
                    $code = $group.'.'.$action;
                    $permissions[$code] = Permission::query()->firstOrCreate(['code' => $code], ['name' => $name, 'group' => $group]);
                    if ($permissions[$code]->wasRecentlyCreated) {
                        $created[] = $code;
                    }
                }
            }

            $catalogRead = ['products.view', 'categories.view', 'subcategories.view', 'brands.view', 'units.view'];
            $catalogWrite = array_values(array_filter(array_keys($permissions), fn ($code) => in_array(explode('.', $code)[0], ['products', 'categories', 'subcategories', 'brands', 'units'], true)));
            $roles = [
                'admin' => ['Administrador', 'Acceso administrativo de la fase base.', array_keys($permissions)],
                'purchasing' => ['Compras', 'Mantenimiento de proveedores, productos y catálogos; recepción de mercadería.', [...$catalogWrite, 'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.deactivate', 'purchases.view', 'purchases.create', 'purchases.confirm', 'inventory.view']],
                'seller' => ['Vendedor', 'Consulta de productos y precios de venta; sin acceso a costos.', $catalogRead],
                'supervisor' => ['Supervisor', 'Supervisión de productos, costos de referencia y configuración; sin escrituras.', [...$catalogRead, 'products.view_cost', 'settings.view']],
                'warehouse' => ['Bodega', 'Consulta de productos y catálogos; ajustes de inventario autorizados.', [...$catalogRead, 'inventory.adjust']],
                'viewer' => ['Consulta', 'Lectura de catálogos expresamente autorizados; sin acceso a costos.', $catalogRead],
            ];
            foreach ($roles as $code => [$name, $description, $codes]) {
                $role = Role::query()->firstOrCreate(['code' => $code], compact('name', 'description') + ['active' => true]);
                if ($role->wasRecentlyCreated) {
                    // Defaults are assigned once. Rerunning never restores revoked grants.
                    $role->permissions()->attach(array_map(fn ($permissionCode) => $permissions[$permissionCode]->getKey(), $codes));
                } elseif (($newGrants = array_values(array_intersect($codes, $created))) !== []) {
                    // A permission created by this run reaches existing roles once; later runs never restore revoked grants.
                    $role->permissions()->syncWithoutDetaching(array_map(fn ($permissionCode) => $permissions[$permissionCode]->getKey(), $newGrants));
                }
            }

            $company = Company::query()->firstOrCreate([], ['trade_name' => 'Empresa de demostración SICOVI', 'currency' => 'GTQ', 'display_timezone' => 'America/Guatemala']);
            $branch = $company->branches()->firstOrCreate([], ['code' => 'PRINCIPAL', 'name' => 'Sucursal principal', 'active' => true]);
            $branch->warehouses()->firstOrCreate([], ['code' => 'PRINCIPAL', 'name' => 'Bodega principal', 'active' => true]);

            foreach ([
                ['code' => 'UND', 'name' => 'Unidad', 'abbreviation' => 'u', 'decimal_places' => 0],
                ['code' => 'KG', 'name' => 'Kilogramo', 'abbreviation' => 'kg', 'decimal_places' => 3],
                ['code' => 'LT', 'name' => 'Litro', 'abbreviation' => 'L', 'decimal_places' => 3],
            ] as $unit) {
                Unit::query()->firstOrCreate(['code' => $unit['code']], $unit + ['active' => true]);
            }
        });
    }
}
