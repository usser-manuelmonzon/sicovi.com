<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\InventoryService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

class OperationsHttpTest extends DatabaseTestCase
{
    private User $admin;

    private int $warehouseId;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->actor('admin');
        $this->warehouseId = (int) DB::table('warehouses')->value('id');
        $categoryId = DB::table('categories')->insertGetId(['code' => 'HTTP-OPS', 'name' => 'Operaciones HTTP']);
        $unitId = (int) DB::table('units')->where('code', 'UND')->value('id');
        $this->productId = DB::table('products')->insertGetId([
            'sku' => 'HTTP-OPS-1', 'name' => 'Producto HTTP', 'category_id' => $categoryId, 'unit_id' => $unitId,
            'sale_price' => '10.00', 'reference_cost' => '5.00', 'minimum_stock' => '0.000', 'active' => true,
        ]);
        DB::table('inventory_balances')->insert(['product_id' => $this->productId, 'warehouse_id' => $this->warehouseId, 'quantity' => '0.000']);
    }

    public function test_temporary_product_route_is_not_available(): void
    {
        $this->get('/producto-seguro')->assertNotFound();
    }

    public function test_supplier_routes_validate_permissions_and_record_audit_events(): void
    {
        $this->actingAs($this->admin);
        $this->from(route('suppliers.create'))
            ->post(route('suppliers.store'), ['name' => ''])
            ->assertRedirect(route('suppliers.create'))
            ->assertSessionHasErrors('name');

        $response = $this->post(route('suppliers.store'), ['name' => 'Proveedor HTTP', 'tax_id' => 'HTTP-1']);
        $supplierId = (int) DB::table('suppliers')->where('name', 'Proveedor HTTP')->value('id');
        $response->assertRedirect(route('suppliers.show', $supplierId));

        $this->put(route('suppliers.update', $supplierId), ['name' => 'Proveedor HTTP Editado', 'tax_id' => 'HTTP-1']);
        $this->patch(route('suppliers.deactivate', $supplierId));

        $this->assertDatabaseHas('audit_events', ['action' => 'suppliers.created']);
        $this->assertDatabaseHas('audit_events', ['action' => 'suppliers.updated']);
        $this->assertDatabaseHas('audit_events', ['action' => 'suppliers.deactivated']);
        $this->assertFalse((bool) DB::table('suppliers')->where('id', $supplierId)->value('active'));
    }

    public function test_purchase_routes_create_confirm_and_void_with_inventory_changes(): void
    {
        $this->actingAs($this->admin);
        $supplierId = DB::table('suppliers')->insertGetId(['name' => 'Proveedor de compras', 'tax_id' => 'HTTP-2']);

        $response = $this->post(route('purchases.store'), [
            'supplier_id' => $supplierId, 'document_date' => '2026-10-27',
            'lines' => [['product_id' => $this->productId, 'quantity' => '2', 'unit_cost' => '5.00']],
        ]);
        $purchaseId = (int) DB::table('purchases')->latest('id')->value('id');
        $response->assertRedirect(route('purchases.show', $purchaseId));
        $itemId = (int) DB::table('purchase_items')->where('purchase_id', $purchaseId)->value('id');

        $this->post(route('purchases.confirm', $purchaseId), [
            'idempotency_key' => 'http-confirm-1',
            'lines' => [['product_id' => $this->productId, 'quantity' => '2', 'unit_cost' => '5.00']],
        ])->assertRedirect(route('purchases.show', $purchaseId));
        $this->assertDatabaseHas('audit_events', ['action' => 'purchases.created']);
        $this->assertSame('2.000', (string) DB::table('inventory_balances')->where('product_id', $this->productId)->value('quantity'));

        $this->post(route('purchases.store'), [
            'supplier_id' => $supplierId, 'document_date' => '2026-10-27',
            'lines' => [['product_id' => $this->productId, 'quantity' => '2', 'unit_cost' => '5.00']],
        ]);
        $secondPurchaseId = (int) DB::table('purchases')->latest('id')->value('id');
        $this->from(route('purchases.show', $secondPurchaseId))
            ->post(route('purchases.confirm', $secondPurchaseId), [
                'idempotency_key' => 'http-confirm-1',
                'lines' => [['product_id' => $this->productId, 'quantity' => '2', 'unit_cost' => '5.00']],
            ])
            ->assertRedirect(route('purchases.show', $secondPurchaseId))
            ->assertSessionHasErrors('idempotency_key');

        $this->post(route('purchases.void', $purchaseId), ['idempotency_key' => 'http-void-1', 'reason' => 'Prueba HTTP'])
            ->assertRedirect(route('purchases.show', $purchaseId));
        $this->assertSame('0.000', (string) DB::table('inventory_balances')->where('product_id', $this->productId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_movements')->where('purchase_item_id', $itemId)->where('movement_type', 'reversal')->count());
    }

    public function test_adjustment_history_detail_and_void_routes_preserve_traceability(): void
    {
        $this->actingAs($this->admin);
        $adjustment = app(InventoryService::class)->applyAdjustment($this->admin, [
            'type' => 'opening', 'adjustment_date' => '2026-10-27', 'reason' => 'Carga inicial HTTP',
            'idempotency_key' => 'http-adjustment-history-1',
            'lines' => [['product_id' => $this->productId, 'quantity' => '5']],
        ]);
        $this->post(route('inventory.adjustments.void', $adjustment), ['reason' => 'Corrección de captura'])
            ->assertRedirect(route('inventory.adjustments.show', $adjustment));

        $this->get(route('inventory.adjustments.index', ['state' => 'voided']))
            ->assertOk()
            ->assertSee($adjustment->code);
        $this->get(route('inventory.adjustments.show', $adjustment))
            ->assertOk()
            ->assertSee('Anulado')
            ->assertSee('Corrección de captura')
            ->assertSee($this->admin->name)
            ->assertDontSee('Anular ajuste');

        $this->assertSame('voided', DB::table('inventory_adjustments')->where('id', $adjustment->id)->value('state'));
        $this->assertSame('0.000', (string) DB::table('inventory_balances')->where('product_id', $this->productId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_movements')->where('adjustment_item_id', DB::table('inventory_adjustment_items')->where('adjustment_id', $adjustment->id)->value('id'))->where('movement_type', 'opening')->count());
        $this->assertSame(1, DB::table('inventory_movements')->where('reversal_of_id', DB::table('inventory_movements')->where('adjustment_item_id', DB::table('inventory_adjustment_items')->where('adjustment_id', $adjustment->id)->value('id'))->value('id'))->count());
        $this->assertDatabaseHas('audit_events', ['action' => 'inventory_adjustments.voided']);
    }

    public function test_adjustment_void_requires_permission_reason_and_rejects_negative_reversal(): void
    {
        $this->actingAs($this->admin);
        $adjustment = app(InventoryService::class)->applyAdjustment($this->admin, [
            'type' => 'opening', 'adjustment_date' => '2026-10-27', 'reason' => 'Carga inicial HTTP',
            'idempotency_key' => 'http-adjustment-history-2',
            'lines' => [['product_id' => $this->productId, 'quantity' => '5']],
        ]);
        $this->post(route('inventory.adjustments.void', $adjustment), [])
            ->assertSessionHasErrors('reason');

        $this->serviceApplyCountAdjustment('4');
        $this->post(route('inventory.adjustments.void', $adjustment), ['reason' => 'No debe revertir'])
            ->assertSessionHasErrors('lines');
        $this->assertSame('applied', DB::table('inventory_adjustments')->where('id', $adjustment->id)->value('state'));

        $viewer = $this->actor('admin', 'viewer');
        $viewer->role->permissions()->where('code', 'inventory.void_adjustment')->detach();
        $this->withoutMiddleware();
        $this->actingAs($viewer);
        $this->post(route('inventory.adjustments.void', $adjustment), ['reason' => 'No autorizado'])
            ->assertForbidden();
    }

    private function serviceApplyCountAdjustment(string $counted): void
    {
        app(InventoryService::class)->applyAdjustment($this->admin, [
            'type' => 'count_adjustment', 'adjustment_date' => '2026-10-27', 'reason' => 'Consumo de prueba',
            'idempotency_key' => 'http-adjustment-count-'.$counted,
            'lines' => [['product_id' => $this->productId, 'counted_quantity' => $counted]],
        ]);
    }

    private function actor(string $role, string $suffix = ''): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'HTTP '.$role, 'email' => 'http-'.$role.$suffix.'@example.test',
            'password' => 'Fixture-only-passphrase-2026!', 'role_id' => Role::where('code', $role)->sole()->id, 'active' => true,
        ])->save();

        return $user;
    }
}
