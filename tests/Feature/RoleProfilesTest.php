<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\InventoryBalance;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\OrganizationContext;
use Database\Seeders\SystemSeeder;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\DatabaseTestCase;

class RoleProfilesTest extends DatabaseTestCase
{
    private const CATALOG_READ = ['brands.view', 'categories.view', 'products.view', 'subcategories.view', 'units.view'];

    public function test_system_seeder_provides_six_stable_roles_without_creating_accounts(): void
    {
        $this->assertSame(['admin', 'purchasing', 'seller', 'supervisor', 'viewer', 'warehouse'], Role::orderBy('code')->pluck('code')->all());
        $this->assertSame('Supervisor', Role::where('code', 'supervisor')->sole()->name);
        $this->assertSame('Bodega', Role::where('code', 'warehouse')->sole()->name);
        $this->assertSame(0, User::count());

        $this->seed(SystemSeeder::class);

        $this->assertSame(6, Role::count());
        $this->assertSame(0, User::count());
    }

    public function test_supervisor_receives_only_catalog_cost_and_settings_read_permissions(): void
    {
        $expected = [...self::CATALOG_READ, 'products.view_cost', 'settings.view'];
        sort($expected);

        $this->assertSame($expected, Role::where('code', 'supervisor')->sole()->permissions()->orderBy('code')->pluck('code')->all());
        $expectedWarehouse = [...self::CATALOG_READ, 'inventory.adjust', 'inventory.view', 'inventory.void_adjustment'];
        sort($expectedWarehouse);
        $this->assertSame($expectedWarehouse, Role::where('code', 'warehouse')->sole()->permissions()->orderBy('code')->pluck('code')->all());
        $this->assertSame(self::CATALOG_READ, Role::where('code', 'seller')->sole()->permissions()->orderBy('code')->pluck('code')->all());
        $this->assertSame(self::CATALOG_READ, Role::where('code', 'viewer')->sole()->permissions()->orderBy('code')->pluck('code')->all());
    }

    public function test_supervisor_can_read_products_with_costs_and_organization_settings(): void
    {
        $product = $this->sampleProduct();
        $this->actingAs($this->actor('supervisor'));

        $this->get(route('products.index'))->assertOk()->assertSee('987,654.32', false);
        $this->get(route('products.show', $product))->assertOk()->assertSee('987,654.32', false);
        $this->get(route('settings.edit'))->assertOk();
    }

    public function test_warehouse_can_read_products_without_receiving_cost_values(): void
    {
        $product = $this->sampleProduct();
        $this->actingAs($this->actor('warehouse'));

        foreach ([route('products.index'), route('products.show', $product)] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('987654.32', false)
                ->assertDontSee('987,654.32', false)
                ->assertDontSee('reference_cost', false);
        }
        $this->get(route('settings.edit'))->assertForbidden();
    }

    #[DataProvider('readOnlyProfiles')]
    public function test_new_profiles_cannot_administer_users_roles_or_write_catalogs_and_settings(string $code): void
    {
        $actor = $this->actor($code);
        $product = $this->sampleProduct();
        $category = $product->category;
        $before = [$product->fresh()->getAttributes(), $category->fresh()->getAttributes(), User::count()];
        $this->actingAs($actor);

        foreach (['categories', 'subcategories', 'brands', 'units'] as $catalog) {
            $this->get(route('catalogs.index', $catalog))->assertOk();
            $this->get(route('catalogs.create', $catalog))->assertForbidden();
            $this->post(route('catalogs.store', $catalog), [])->assertForbidden();
        }
        $this->get(route('users.index'))->assertForbidden();
        $this->get(route('users.create'))->assertForbidden();
        $this->post(route('users.store'), [])->assertForbidden();
        $this->put(route('users.update', $actor), [])->assertForbidden();
        $this->patch(route('users.deactivate', $actor), ['active' => false])->assertForbidden();
        $this->put(route('users.reset-password', $actor), [])->assertForbidden();
        $this->get(route('roles.index'))->assertForbidden();
        $this->put(route('roles.update', $actor->role), [])->assertForbidden();
        $this->get(route('products.create'))->assertForbidden();
        $this->get(route('products.edit', $product))->assertForbidden();
        $this->post(route('products.store'), [])->assertForbidden();
        $this->put(route('products.update', $product), [])->assertForbidden();
        $this->patch(route('products.deactivate', $product))->assertForbidden();
        $this->put(route('catalogs.update', ['catalog' => 'categories', 'id' => $category->id]), [])->assertForbidden();
        $this->put(route('settings.update'), [])->assertForbidden();

        $this->assertSame($before, [$product->fresh()->getAttributes(), $category->fresh()->getAttributes(), User::count()]);
    }

    public static function readOnlyProfiles(): array
    {
        return [['supervisor'], ['warehouse']];
    }

    public function test_repeated_system_seed_preserves_custom_names_grants_and_revocations_for_all_profiles(): void
    {
        $supervisor = Role::where('code', 'supervisor')->sole();
        $supervisor->update(['name' => 'Supervisión personalizada', 'description' => 'Preferencia administrativa conservada.']);
        $cost = Permission::where('code', 'products.view_cost')->sole();
        $customGrant = Permission::where('code', 'products.create')->sole();
        $supervisor->permissions()->detach($cost->id);
        $supervisor->permissions()->attach($customGrant->id);
        $warehouse = Role::where('code', 'warehouse')->sole();
        $warehouse->update(['name' => 'Almacén personalizado', 'active' => false]);
        $warehouse->permissions()->detach(Permission::where('code', 'products.view')->sole()->id);
        $purchasing = Role::where('code', 'purchasing')->sole();
        $purchasing->update(['name' => 'Compras e inventario personalizado']);
        $expectedSupervisor = $supervisor->permissions()->orderBy('code')->pluck('code')->all();
        $expectedWarehouse = $warehouse->permissions()->orderBy('code')->pluck('code')->all();
        $expectedPurchasing = $purchasing->permissions()->orderBy('code')->pluck('code')->all();

        $this->seed(SystemSeeder::class);

        $this->assertSame(6, Role::count());
        $this->assertSame(0, User::count());
        $this->assertSame('Supervisión personalizada', $supervisor->fresh()->name);
        $this->assertSame('Preferencia administrativa conservada.', $supervisor->fresh()->description);
        $this->assertSame($expectedSupervisor, $supervisor->permissions()->orderBy('code')->pluck('code')->all());
        $this->assertSame('Almacén personalizado', $warehouse->fresh()->name);
        $this->assertFalse($warehouse->fresh()->active);
        $this->assertSame($expectedWarehouse, $warehouse->permissions()->orderBy('code')->pluck('code')->all());
        $this->assertSame('Compras e inventario personalizado', $purchasing->fresh()->name);
        $this->assertSame($expectedPurchasing, $purchasing->permissions()->orderBy('code')->pluck('code')->all());
    }

    private function actor(string $code): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Usuario de pruebas '.$code,
            'email' => $code.'-profile@example.test',
            'password' => 'Fixture-only-passphrase-2026!',
            'role_id' => Role::where('code', $code)->sole()->id,
            'active' => true,
        ])->save();

        return $user;
    }

    private function sampleProduct(): Product
    {
        $category = Category::create(['code' => 'ROLE-TEST', 'name' => 'Categoría de perfiles']);
        $product = Product::create([
            'sku' => 'ROLE-PRODUCT', 'name' => 'Producto de perfiles',
            'category_id' => $category->id, 'unit_id' => Unit::where('code', 'UND')->sole()->id,
            'sale_price' => '25.00', 'reference_cost' => '987654.32', 'minimum_stock' => '0.000',
        ]);
        InventoryBalance::create([
            'product_id' => $product->id,
            'warehouse_id' => app(OrganizationContext::class)->warehouse()->id,
            'quantity' => '0.000',
        ]);

        return $product;
    }
}
