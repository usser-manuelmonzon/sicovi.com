<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\DecimalService;
use App\Services\InventoryService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\DatabaseTestCase;

/**
 * Servicio central de inventario (contrato YOURGUEN-DB-002, sección 7).
 *
 * La conciliación saldo/movimientos es DEFERRED y la suite nunca confirma su transacción, por eso
 * cada escenario llama a settle() para forzar la evaluación, igual que InventorySchemaTest.
 */
class InventoryServiceTest extends DatabaseTestCase
{
    private InventoryService $service;

    private User $admin;

    private User $warehouseUser;

    private User $purchasing;

    private int $warehouseId;

    private int $unitId;

    private int $productA; // unidad sin decimales (UND)

    private int $productB; // unidad con 3 decimales (KG)

    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InventoryService::class);
        $this->admin = $this->actor('admin', 'admin');
        $this->warehouseUser = $this->actor('warehouse', 'warehouse');
        $this->purchasing = $this->actor('purchasing', 'purchasing');
        $this->warehouseId = (int) DB::table('warehouses')->value('id');
        $this->unitId = (int) DB::table('units')->where('code', 'UND')->value('id');
        $kgId = (int) DB::table('units')->where('code', 'KG')->value('id');
        $categoryId = DB::table('categories')->insertGetId(['code' => 'T-SVC', 'name' => 'Categoría de pruebas servicio']);
        $this->productA = DB::table('products')->insertGetId(['sku' => 'T-SVC-A', 'name' => 'Producto A', 'category_id' => $categoryId, 'unit_id' => $this->unitId]);
        $this->productB = DB::table('products')->insertGetId(['sku' => 'T-SVC-B', 'name' => 'Producto B', 'category_id' => $categoryId, 'unit_id' => $kgId]);
        foreach ([$this->productA, $this->productB] as $productId) {
            DB::table('inventory_balances')->insert(['product_id' => $productId, 'warehouse_id' => $this->warehouseId, 'quantity' => '0.000']);
        }
        $this->supplierId = DB::table('suppliers')->insertGetId(['name' => 'Proveedor servicio', 'tax_id' => '888-8']);
    }

    public function test_an_opening_adjustment_creates_document_line_movement_and_balance_together(): void
    {
        $adjustment = $this->service->applyAdjustment($this->admin, $this->data('opening', [['product_id' => $this->productA, 'quantity' => '100']]));
        $this->settle();

        $this->assertStringStartsWith('AJU-', $adjustment->code);
        $this->assertSame('applied', $adjustment->state);
        $this->assertSame('100.000', $this->balanceOf($this->productA));
        $movement = DB::table('inventory_movements')->where('product_id', $this->productA)->sole();
        $this->assertSame('opening', $movement->movement_type);
        $this->assertSame('0.000', $movement->balance_before);
        $this->assertSame('100.000', $movement->balance_after);
        $this->assertSame($this->admin->id, (int) $movement->actor_id);
    }

    public function test_warehouse_role_can_adjust_but_purchasing_cannot(): void
    {
        $this->service->applyAdjustment($this->warehouseUser, $this->data('opening', [['product_id' => $this->productA, 'quantity' => '5']]));
        $this->settle();
        $this->assertSame('5.000', $this->balanceOf($this->productA));

        try {
            $this->service->applyAdjustment($this->purchasing, $this->data('opening', [['product_id' => $this->productA, 'quantity' => '5']]));
            $this->fail('Compras no tiene inventory.adjust.');
        } catch (AuthorizationException) {
            $this->assertSame('5.000', $this->balanceOf($this->productA));
        }
    }

    public function test_a_count_adjustment_uses_the_locked_balance_and_skips_unchanged_lines(): void
    {
        $this->service->applyAdjustment($this->admin, $this->data('opening', [
            ['product_id' => $this->productA, 'quantity' => '100'],
            ['product_id' => $this->productB, 'quantity' => '10.500'],
        ]));
        $count = $this->service->applyAdjustment($this->admin, $this->data('count_adjustment', [
            ['product_id' => $this->productA, 'counted_quantity' => '95'],
            ['product_id' => $this->productB, 'counted_quantity' => '10.500'], // sin diferencia
        ]));
        $this->settle();

        $this->assertSame('95.000', $this->balanceOf($this->productA));
        $this->assertSame('10.500', $this->balanceOf($this->productB));
        $item = DB::table('inventory_adjustment_items')->where('adjustment_id', $count->id)->sole();
        $this->assertSame('-5.000', $item->quantity_delta);
        $this->assertSame('100.000', $item->recorded_quantity);
        $this->assertSame('95.000', $item->counted_quantity);
    }

    public function test_a_count_equal_to_the_balance_is_rejected(): void
    {
        $this->service->applyAdjustment($this->admin, $this->data('opening', [['product_id' => $this->productA, 'quantity' => '10']]));

        $this->assertInvalid('lines', fn () => $this->service->applyAdjustment($this->admin, $this->data('count_adjustment', [['product_id' => $this->productA, 'counted_quantity' => '10']])));
    }

    public function test_voiding_an_adjustment_reverses_every_movement(): void
    {
        $adjustment = $this->service->applyAdjustment($this->admin, $this->data('opening', [
            ['product_id' => $this->productA, 'quantity' => '40'],
            ['product_id' => $this->productB, 'quantity' => '2.250'],
        ]));
        $voided = $this->service->voidAdjustment($this->warehouseUser, $adjustment, 'Captura equivocada');
        $this->settle();

        $this->assertSame('voided', $voided->state);
        $this->assertSame('0.000', $this->balanceOf($this->productA));
        $this->assertSame('0.000', $this->balanceOf($this->productB));
        $this->assertSame(2, DB::table('inventory_movements')->where('movement_type', 'reversal')->count());
        $this->assertSame('Captura equivocada', DB::table('inventory_adjustments')->where('id', $adjustment->id)->value('void_reason'));
        $this->assertInvalid('state', fn () => $this->service->voidAdjustment($this->admin, $adjustment, 'Otra vez'));
    }

    public function test_voiding_is_refused_when_the_stock_was_already_consumed(): void
    {
        $opening = $this->service->applyAdjustment($this->admin, $this->data('opening', [['product_id' => $this->productA, 'quantity' => '100']]));
        $this->service->applyAdjustment($this->admin, $this->data('count_adjustment', [['product_id' => $this->productA, 'counted_quantity' => '40']]));

        $this->assertInvalid('lines', fn () => $this->service->voidAdjustment($this->admin, $opening, 'Intento'));

        $this->settle();
        $this->assertSame('applied', DB::table('inventory_adjustments')->where('id', $opening->id)->value('state'));
        $this->assertSame('40.000', $this->balanceOf($this->productA));
    }

    public function test_repeating_the_idempotency_key_returns_the_same_adjustment_without_moving_stock_again(): void
    {
        $data = $this->data('opening', [['product_id' => $this->productA, 'quantity' => '7']]) + ['idempotency_key' => 'clave-123'];
        $first = $this->service->applyAdjustment($this->admin, $data);
        $second = $this->service->applyAdjustment($this->admin, $data);
        $this->settle();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('7.000', $this->balanceOf($this->productA));
        $this->assertSame(1, DB::table('inventory_movements')->count());
    }

    public function test_quantities_respect_the_precision_of_the_unit(): void
    {
        $this->assertInvalid('lines', fn () => $this->service->applyAdjustment($this->admin, $this->data('opening', [['product_id' => $this->productA, 'quantity' => '2.5']])));

        $this->service->applyAdjustment($this->admin, $this->data('opening', [['product_id' => $this->productB, 'quantity' => '2.500']]));
        $this->settle();
        $this->assertSame('2.500', $this->balanceOf($this->productB));
    }

    public function test_invalid_input_becomes_a_validation_error_not_a_crash(): void
    {
        $lines = [['product_id' => $this->productA, 'quantity' => '1']];

        $this->assertInvalid('adjustment_date', fn () => $this->service->applyAdjustment($this->admin, ['adjustment_date' => '2026-13-45'] + $this->data('opening', $lines)));
        $missingDate = $this->data('opening', $lines);
        unset($missingDate['adjustment_date']);
        $this->assertInvalid('adjustment_date', fn () => $this->service->applyAdjustment($this->admin, $missingDate));
        $this->assertInvalid('type', fn () => $this->service->applyAdjustment($this->admin, ['type' => 'otro'] + $this->data('opening', $lines)));
        $this->assertInvalid('reason', fn () => $this->service->applyAdjustment($this->admin, ['reason' => '  '] + $this->data('opening', $lines)));
        $this->assertInvalid('lines', fn () => $this->service->applyAdjustment($this->admin, $this->data('opening', [])));
        $this->assertInvalid('lines', fn () => $this->service->applyAdjustment($this->admin, $this->data('opening', [$lines[0], $lines[0]])));
        $this->assertInvalid('lines', fn () => $this->service->applyAdjustment($this->admin, $this->data('opening', [['product_id' => 999999, 'quantity' => '1']])));
        $this->assertSame(0, DB::table('inventory_adjustments')->count());
    }

    public function test_an_inactive_product_cannot_receive_adjustments(): void
    {
        DB::table('products')->where('id', $this->productA)->update(['active' => false]);

        $this->assertInvalid('lines', fn () => $this->service->applyAdjustment($this->admin, $this->data('opening', [['product_id' => $this->productA, 'quantity' => '1']])));
    }

    public function test_a_purchase_is_received_and_then_reversed_through_the_service(): void
    {
        $purchaseId = $this->purchase('FAC-1', [[$this->productA, '10.000', '5.00']]);
        $itemId = (int) DB::table('purchase_items')->where('purchase_id', $purchaseId)->value('id');

        $this->service->receivePurchaseLines($this->purchasing, $this->warehouseId, [
            ['purchase_item_id' => $itemId, 'product_id' => $this->productA, 'quantity' => '10.000'],
        ]);
        $this->confirmOnly($purchaseId);
        $this->settle();
        $this->assertSame('10.000', $this->balanceOf($this->productA));
        $this->assertSame('purchase', DB::table('inventory_movements')->where('purchase_item_id', $itemId)->value('movement_type'));

        $this->voidOnly($purchaseId);
        $this->service->reversePurchaseLines($this->purchasing, $this->warehouseId, [$itemId], 'Error de captura');
        $this->settle();
        $this->assertSame('0.000', $this->balanceOf($this->productA));
        $this->assertSame(1, DB::table('inventory_movements')->where('movement_type', 'reversal')->count());
    }

    public function test_purchase_quantities_also_respect_the_unit_precision(): void
    {
        $this->assertInvalid('lines', fn () => $this->service->receivePurchaseLines($this->purchasing, $this->warehouseId, [
            ['purchase_item_id' => 1, 'product_id' => $this->productA, 'quantity' => '2.500'],
        ]));
    }

    private function data(string $type, array $lines): array
    {
        return [
            'type' => $type, 'adjustment_date' => '2026-10-22', 'reason' => 'Motivo de prueba',
            'reference' => null, 'notes' => null, 'lines' => $lines,
        ];
    }

    private function assertInvalid(string $field, callable $operation): void
    {
        try {
            $operation();
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());

            return;
        }
        $this->fail("Se esperaba un error de validación en {$field}.");
    }

    /** Fuerza la evaluación de las comprobaciones DEFERRED que PostgreSQL haría al confirmar. */
    private function settle(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    private function balanceOf(int $productId): string
    {
        return (string) DB::table('inventory_balances')->where('product_id', $productId)->where('warehouse_id', $this->warehouseId)->value('quantity');
    }

    /** @param list<array{0:int,1:string,2:string}> $lines producto, cantidad, costo */
    private function purchase(string $reference, array $lines): int
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'supplier_id' => $this->supplierId, 'warehouse_id' => $this->warehouseId, 'document_date' => '2026-10-22',
            'supplier_reference' => $reference, 'created_by' => $this->admin->id,
        ]);
        foreach ($lines as [$productId, $quantity, $cost]) {
            DB::table('purchase_items')->insert([
                'purchase_id' => $purchaseId, 'product_id' => $productId, 'unit_id' => $this->unitId,
                'product_sku' => 'SKU-'.$productId, 'product_name' => 'Producto '.$productId,
                'quantity' => $quantity, 'unit_cost' => $cost, 'line_total' => app(DecimalService::class)->lineTotal($quantity, $cost),
            ]);
        }

        return $purchaseId;
    }

    private function confirmOnly(int $purchaseId): void
    {
        $lines = DB::table('purchase_items')->where('purchase_id', $purchaseId)->pluck('line_total')->map(fn ($value) => (string) $value)->all();
        DB::table('purchases')->where('id', $purchaseId)->update([
            'state' => 'confirmed', 'total' => app(DecimalService::class)->total($lines),
            'confirmed_by' => $this->admin->id, 'confirmed_at' => now(),
        ]);
    }

    private function voidOnly(int $purchaseId): void
    {
        DB::table('purchases')->where('id', $purchaseId)->update([
            'state' => 'voided', 'voided_by' => $this->purchasing->id, 'voided_at' => now(), 'void_reason' => 'Error de captura',
        ]);
    }

    private function actor(string $role, string $suffix): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Usuario de pruebas '.$suffix,
            'email' => 'inventory-service-'.$suffix.'@example.test',
            'password' => 'Fixture-only-passphrase-2026!',
            'role_id' => Role::where('code', $role)->sole()->id,
            'active' => true,
        ])->save();

        return $user;
    }
}
