<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Support\Catalogs;
use Database\Seeders\DemoSeeder;
use Tests\DatabaseTestCase;

class InterfaceTest extends DatabaseTestCase
{
    public function test_all_administration_screens_render_with_real_catalogs(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = new User;
        $admin->forceFill(['name' => 'Revisión de interfaz', 'email' => 'interfaz@example.test', 'password' => 'SoloPruebas!2026', 'role_id' => Role::where('code', 'admin')->sole()->id, 'active' => true])->save();
        $this->actingAs($admin);
        $product = Product::firstOrFail();
        $routes = ['/', '/account', '/products', '/products/create', '/products/'.$product->id, '/products/'.$product->id.'/edit', '/users', '/users/create', '/users/'.$admin->id.'/edit', '/roles', '/roles/'.$admin->role_id.'/edit', '/settings'];
        foreach (['categories', 'subcategories', 'brands', 'units'] as $catalog) {
            $class = Catalogs::get($catalog)[0];
            $routes[] = '/catalogs/'.$catalog;
            $routes[] = '/catalogs/'.$catalog.'/create';
            $routes[] = '/catalogs/'.$catalog.'/'.$class::firstOrFail()->id.'/edit';
        }
        foreach ($routes as $route) {
            $this->get($route)->assertOk()->assertSee('SICOVI')->assertDontSee('Stack trace');
        }
    }

    public function test_sidebar_organizes_existing_routes_by_module_and_report_type(): void
    {
        $this->seed(DemoSeeder::class);
        $admin = new User;
        $admin->forceFill(['name' => 'Menú', 'email' => 'menu@example.test', 'password' => 'SoloPruebas!2026', 'role_id' => Role::where('code', 'admin')->sole()->id, 'active' => true])->save();

        $response = $this->actingAs($admin)->get('/');

        foreach ([
            'Almacén Central', 'Compras', 'Proveedores', 'Registrar compra', 'Consultar compras',
            'Ventas', 'Clientes', 'Registrar venta', 'Consultar ventas', 'Inventario',
            'Registrar ajuste', 'Existencias y movimientos', 'Historial de ajustes',
            'Reportes', 'Reporte de ventas', 'Reporte de compras', 'Reporte de existencias',
            'Reporte Kardex', 'Reporte de anulaciones', 'Administración', 'Usuarios',
            'Roles y permisos', 'Datos de la empresa', 'Mi cuenta',
        ] as $label) {
            $response->assertSee($label);
        }

        foreach ([
            route('products.index'), route('suppliers.index'), route('purchases.create'), route('purchases.index'),
            route('customers.index'), route('sales.create'), route('sales.index'), route('inventory.index'),
            route('inventory.adjustments.create'), route('inventory.adjustments.index'),
            route('reports.index', ['report' => 'sales']), route('reports.index', ['report' => 'purchases']),
            route('reports.index', ['report' => 'inventory']), route('reports.index', ['report' => 'kardex']),
            route('reports.index', ['report' => 'cancellations']), route('users.index'), route('roles.index'),
            route('settings.edit'), route('account.edit'),
        ] as $href) {
            $response->assertSee('href="'.$href.'"', false);
        }

        $response->assertDontSee('>Operaciones<', false)->assertDontSee('>Seguridad<', false);
    }

    public function test_catalog_product_and_filter_arrays_are_rejected_as_validation_errors(): void
    {
        $admin = new User;
        $admin->forceFill(['name' => 'Validación', 'email' => 'validacion@example.test', 'password' => 'SoloPruebas!2026', 'role_id' => Role::where('code', 'admin')->sole()->id, 'active' => true])->save();
        $this->actingAs($admin)->postJson('/catalogs/brands', ['name' => ['invalido'], 'active' => '1'])->assertUnprocessable();
        $this->postJson('/products', ['name' => ['invalido'], 'sku' => ['invalido'], 'barcode' => ['invalido']])->assertUnprocessable();
        $this->getJson('/products?q[]=invalido')->assertUnprocessable();
        $this->getJson('/catalogs/brands?q[]=invalido')->assertUnprocessable();
    }
}
