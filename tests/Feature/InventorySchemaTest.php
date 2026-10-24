<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\DecimalService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

/**
 * YOURGUEN-DB-002: ajustes, movimientos (Kardex) y conciliación saldo/movimientos.
 *
 * Las comprobaciones de conciliación son DEFERRED (se evalúan al confirmar la transacción). La suite
 * trabaja dentro de una transacción que nunca se confirma, por eso cada escenario llama a settle(),
 * que fuerza esa evaluación con SET CONSTRAINTS ALL IMMEDIATE.
 */
class InventorySchemaTest extends DatabaseTestCase
{
    private const UNIQUE = '23505';

    private const CHECK = '23514';

    private const FOREIGN_KEY = '23503';

    private User $author;

    private User $reviewer;

    private int $warehouseId;

    private int $unitId;

    private int $productA;

    private int $productB;

    private int $supplierId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->author = $this->actor('admin', 'author');
        $this->reviewer = $this->actor('purchasing', 'reviewer');
        $this->warehouseId = (int) DB::table('warehouses')->value('id');
        $this->unitId = (int) DB::table('units')->orderBy('id')->value('id');
        $categoryId = DB::table('categories')->insertGetId(['code' => 'T-DB002', 'name' => 'Categoría de pruebas DB002']);
        $this->productA = DB::table('products')->insertGetId(['sku' => 'T-DB002-A', 'name' => 'Producto A', 'category_id' => $categoryId, 'unit_id' => $this->unitId]);
        $this->productB = DB::table('products')->insertGetId(['sku' => 'T-DB002-B', 'name' => 'Producto B', 'category_id' => $categoryId, 'unit_id' => $this->unitId]);
        foreach ([$this->productA, $this->productB] as $productId) {
            DB::table('inventory_balances')->insert(['product_id' => $productId, 'warehouse_id' => $this->warehouseId, 'quantity' => '0.000']);
        }
        $this->supplierId = DB::table('suppliers')->insertGetId(['name' => 'Proveedor DB002', 'tax_id' => '777-7']);
    }

    public function test_an_opening_balance_is_applied_with_its_movement_and_reconciles(): void
    {
        [, , $movementId] = $this->applyEntry('opening', 'O1', $this->productA, '100.000');
        $this->settle();

        $this->assertSame('100.000', $this->balanceOf($this->productA));
        $movement = DB::table('inventory_movements')->where('id', $movementId)->first();
        $this->assertSame('100.000', $movement->quantity);
        $this->assertSame('0.000', $movement->balance_before);
        $this->assertSame('100.000', $movement->balance_after);
    }

    public function test_balance_and_movements_can_never_diverge(): void
    {
        $this->assertRejected(self::CHECK, function () {
            $adjustmentId = $this->adjustment('opening', 'O2');
            $this->adjustmentItem($adjustmentId, 'opening', $this->productB, '50.000');
        }, 'ajuste sin movimiento de inventario');

        $this->assertRejected(self::CHECK, function () {
            $itemId = $this->adjustmentItem($this->adjustment('opening', 'O3'), 'opening', $this->productB, '50.000');
            $this->movement(['product_id' => $this->productB, 'movement_type' => 'opening', 'quantity' => '50.000', 'balance_before' => '0.000', 'balance_after' => '50.000', 'adjustment_item_id' => $itemId, 'reason' => 'Inventario inicial']);
        }, 'movimiento sin actualizar el saldo');

        $this->assertRejected(self::CHECK, fn () => $this->setBalance($this->productB, '7.000'), 'saldo modificado sin movimiento');

        $this->assertRejected(self::CHECK, function () {
            $itemId = $this->adjustmentItem($this->adjustment('opening', 'O8'), 'opening', $this->productB, '10.000');
            $this->movement(['product_id' => $this->productB, 'movement_type' => 'opening', 'quantity' => '9.000', 'balance_before' => '0.000', 'balance_after' => '9.000', 'adjustment_item_id' => $itemId, 'reason' => 'Inventario inicial']);
            $this->setBalance($this->productB, '9.000');
        }, 'movimiento con cantidad distinta a la línea del ajuste');

        $this->applyEntry('opening', 'OK', $this->productA, '5.000');
        $this->assertRejected(self::CHECK, function () {
            $itemId = $this->adjustmentItem($this->adjustment('extraordinary_entry', 'P18'), 'extraordinary_entry', $this->productA, '5.000');
            $this->movement(['product_id' => $this->productA, 'movement_type' => 'extraordinary_entry', 'quantity' => '5.000', 'balance_before' => '0.000', 'balance_after' => '5.000', 'adjustment_item_id' => $itemId, 'reason' => 'Entrada']);
            $this->setBalance($this->productA, '10.000');
        }, 'saldo posterior del último movimiento distinto del saldo actual');
        $this->settle();
        $this->assertSame('5.000', $this->balanceOf($this->productA));
    }

    public function test_adjustment_lines_follow_the_shape_of_their_type(): void
    {
        $this->assertRejected(self::CHECK, fn () => $this->adjustmentItem($this->adjustment('opening', 'S1'), 'opening', $this->productB, '-5.000'), 'apertura con cantidad negativa');
        $this->assertRejected(self::CHECK, fn () => $this->adjustment('opening', 'S2'), 'ajuste sin líneas');
        $this->assertRejected(self::FOREIGN_KEY, fn () => $this->adjustmentItem($this->adjustment('opening', 'S3'), 'extraordinary_entry', $this->productB, '5.000'), 'tipo de línea distinto al de la cabecera');
        $this->assertRejected(self::CHECK, fn () => $this->adjustment('opening', 'S4', 'Motivo de prueba', ['state' => 'voided', 'voided_by' => $this->reviewer->id, 'voided_at' => now(), 'void_reason' => 'x']), 'ajuste insertado ya anulado');
        $count = $this->adjustment('count_adjustment', 'S5');
        $this->assertRejected(self::CHECK, fn () => $this->adjustmentItem($count, 'count_adjustment', $this->productA, '9.000', '95.000', '100.000'), 'diferencia distinta de contado menos registrado');
        $this->assertRejected(self::CHECK, fn () => $this->adjustmentItem($count, 'count_adjustment', $this->productA, '0.000', '95.000', '95.000'), 'diferencia cero');
        $this->assertRejected(self::CHECK, fn () => $this->adjustmentItem($count, 'count_adjustment', $this->productA, '2.000'), 'conteo sin cantidad contada');
    }

    public function test_a_count_adjustment_is_compared_against_the_current_locked_balance(): void
    {
        $this->applyEntry('opening', 'O1', $this->productA, '100.000');
        $this->applyCount('C1', $this->productA, '95.000'); // -5
        $this->settle();
        $this->assertSame('95.000', $this->balanceOf($this->productA));

        $this->assertRejected(self::CHECK, function () {
            $itemId = $this->adjustmentItem($this->adjustment('count_adjustment', 'C2', 'Conteo'), 'count_adjustment', $this->productA, '-3.000', '97.000', '100.000');
            $this->movement(['product_id' => $this->productA, 'movement_type' => 'count_adjustment', 'quantity' => '-3.000', 'balance_before' => '95.000', 'balance_after' => '92.000', 'adjustment_item_id' => $itemId, 'reason' => 'Conteo']);
            $this->setBalance($this->productA, '92.000');
        }, 'saldo registrado desactualizado');

        $this->assertRejected(self::CHECK, function () {
            $itemId = $this->adjustmentItem($this->adjustment('count_adjustment', 'C3', 'Conteo'), 'count_adjustment', $this->productA, '-100.000', '0.000', '100.000');
            $this->movement(['product_id' => $this->productA, 'movement_type' => 'count_adjustment', 'quantity' => '-100.000', 'balance_before' => '95.000', 'balance_after' => '-5.000', 'adjustment_item_id' => $itemId, 'reason' => 'Conteo']);
        }, 'ajuste negativo que deja el saldo bajo cero');

        $this->applyCount('C7', $this->productA, '97.000'); // +2
        $this->settle();
        $this->assertSame('97.000', $this->balanceOf($this->productA));
    }

    public function test_a_movement_needs_exactly_one_matching_origin_and_a_reason(): void
    {
        [, $adjustmentItemId] = $this->applyEntry('opening', 'O1', $this->productA, '10.000');
        $purchaseItemId = $this->draftPurchaseItem($this->productA, '1.000', '1.00');
        $base = ['product_id' => $this->productA, 'quantity' => '1.000', 'balance_before' => '10.000', 'balance_after' => '11.000'];

        $this->assertRejected(self::CHECK, fn () => $this->movement($base + ['movement_type' => 'purchase', 'purchase_item_id' => $purchaseItemId, 'adjustment_item_id' => $adjustmentItemId]), 'movimiento con dos orígenes');
        $this->assertRejected(self::CHECK, fn () => $this->movement($base + ['movement_type' => 'purchase']), 'movimiento sin origen');
        $this->assertRejected(self::CHECK, fn () => $this->movement($base + ['movement_type' => 'purchase', 'adjustment_item_id' => $adjustmentItemId]), 'tipo de movimiento que no corresponde al origen');
        $this->assertRejected(self::CHECK, fn () => $this->movement($base + ['movement_type' => 'extraordinary_entry', 'adjustment_item_id' => $adjustmentItemId, 'reason' => null]), 'movimiento de ajuste sin motivo');
        $this->assertRejected(self::CHECK, fn () => $this->movement(['balance_after' => '12.000'] + $base + ['movement_type' => 'purchase', 'purchase_item_id' => $purchaseItemId]), 'saldo posterior distinto de anterior más cantidad');
    }

    public function test_movements_cannot_be_edited_or_deleted(): void
    {
        [, , $movementId] = $this->applyEntry('opening', 'O1', $this->productA, '10.000');
        $this->settle();

        $this->assertRejected(self::CHECK, fn () => DB::table('inventory_movements')->where('id', $movementId)->update(['reason' => 'otro']), 'editar un movimiento');
        $this->assertRejected(self::CHECK, fn () => DB::table('inventory_movements')->where('id', $movementId)->delete(), 'borrar un movimiento');
    }

    public function test_confirming_a_purchase_is_receiving_it_exactly_once(): void
    {
        $this->applyEntry('opening', 'O1', $this->productA, '97.000');
        $purchase = $this->purchase('PA', [[$this->productA, '10.000', '5.00'], [$this->productB, '2.500', '4.00']]);

        $this->assertRejected(self::CHECK, fn () => $this->confirmOnly($purchase), 'confirmar sin movimientos de entrada');
        $this->assertRejected(self::CHECK, fn () => $this->receive($purchase, '9.000'), 'confirmar con cantidad de entrada distinta');
        $this->assertRejected(self::CHECK, function () {
            $draft = $this->purchase('PX', []);
            $this->confirmOnly($draft);
        }, 'confirmar una compra sin líneas');

        $this->receive($purchase);
        $this->settle();
        $this->assertSame('107.000', $this->balanceOf($this->productA));
        $this->assertSame('2.500', $this->balanceOf($this->productB));
        $this->assertSame(2, DB::table('inventory_movements')->where('movement_type', 'purchase')->count());

        $itemId = (int) DB::table('purchase_items')->where('purchase_id', $purchase)->where('product_id', $this->productA)->value('id');
        $this->assertRejected(self::UNIQUE, fn () => $this->movement(['product_id' => $this->productA, 'movement_type' => 'purchase', 'quantity' => '10.000', 'balance_before' => '107.000', 'balance_after' => '117.000', 'purchase_item_id' => $itemId]), 'segunda entrada para la misma línea de compra');

        $draftItem = $this->draftPurchaseItem($this->productA, '1.000', '1.00');
        $this->assertRejected(self::CHECK, function () use ($draftItem) {
            $this->movement(['product_id' => $this->productA, 'movement_type' => 'purchase', 'quantity' => '1.000', 'balance_before' => '107.000', 'balance_after' => '108.000', 'purchase_item_id' => $draftItem]);
            $this->setBalance($this->productA, '108.000');
        }, 'entrada de una compra en borrador');
    }

    public function test_voiding_a_purchase_requires_exactly_one_reversal_per_entry(): void
    {
        $this->applyEntry('opening', 'O1', $this->productA, '97.000');
        $purchase = $this->purchase('PA', [[$this->productA, '10.000', '5.00'], [$this->productB, '2.500', '4.00']]);
        $this->receive($purchase);
        $this->settle();

        $this->assertRejected(self::CHECK, fn () => $this->voidOnly($purchase), 'anular sin revertir los movimientos');
        $this->assertRejected(self::CHECK, function () use ($purchase) {
            $this->voidOnly($purchase);
            $this->reverseItem($purchase, $this->productA, '-9.000');
            $this->setBalance($this->productA, '98.000');
        }, 'reversión con cantidad distinta');

        $this->voidWithReversals($purchase);
        $this->settle();
        $this->assertSame('97.000', $this->balanceOf($this->productA));
        $this->assertSame('0.000', $this->balanceOf($this->productB));

        $this->assertRejected(self::UNIQUE, function () use ($purchase) {
            $this->reverseItem($purchase, $this->productA, '-10.000');
            $this->setBalance($this->productA, '87.000');
        }, 'revertir dos veces el mismo movimiento');

        $reversalId = (int) DB::table('inventory_movements')->where('movement_type', 'reversal')->where('product_id', $this->productA)->value('id');
        $itemId = (int) DB::table('purchase_items')->where('purchase_id', $purchase)->where('product_id', $this->productA)->value('id');
        $this->assertRejected(self::CHECK, fn () => $this->movement(['product_id' => $this->productA, 'movement_type' => 'reversal', 'quantity' => '10.000', 'balance_before' => '97.000', 'balance_after' => '107.000', 'purchase_item_id' => $itemId, 'reversal_of_id' => $reversalId, 'actor_id' => $this->reviewer->id, 'reason' => 'x']), 'revertir una reversión');

        $other = $this->purchase('PC', [[$this->productA, '1.000', '1.00']]);
        $this->receive($other);
        $this->settle();
        $this->assertRejected(self::CHECK, function () use ($other) {
            $this->reverseItem($other, $this->productA, '-1.000');
            $this->setBalance($this->productA, '97.000');
        }, 'reversión de una compra que no está anulada');
    }

    public function test_adjustments_are_immutable_and_are_voided_with_their_reversal(): void
    {
        $adjustmentId = $this->applyEntry('extraordinary_entry', 'E1', $this->productB, '20.000')[0];
        $this->settle();

        $this->assertRejected(self::CHECK, fn () => $this->voidAdjustmentOnly($adjustmentId), 'anular un ajuste sin reversión');
        $this->assertRejected(self::CHECK, fn () => DB::table('inventory_adjustments')->where('id', $adjustmentId)->update(['reason' => 'otro']), 'cambiar el motivo de un ajuste');
        $this->assertRejected(self::CHECK, fn () => DB::table('inventory_adjustments')->where('id', $adjustmentId)->delete(), 'borrar un ajuste');
        $this->assertRejected(self::CHECK, fn () => DB::table('inventory_adjustment_items')->where('adjustment_id', $adjustmentId)->update(['product_name' => 'x']), 'editar una línea de ajuste');

        $itemId = (int) DB::table('inventory_adjustment_items')->where('adjustment_id', $adjustmentId)->value('id');
        $originalId = (int) DB::table('inventory_movements')->where('adjustment_item_id', $itemId)->value('id');
        $this->voidAdjustmentOnly($adjustmentId);
        $this->movement(['product_id' => $this->productB, 'movement_type' => 'reversal', 'quantity' => '-20.000', 'balance_before' => '20.000', 'balance_after' => '0.000', 'adjustment_item_id' => $itemId, 'reversal_of_id' => $originalId, 'actor_id' => $this->reviewer->id, 'reason' => 'Anulación']);
        $this->setBalance($this->productB, '0.000');
        $this->settle();

        $this->assertSame('voided', DB::table('inventory_adjustments')->where('id', $adjustmentId)->value('state'));
        $this->assertSame('0.000', $this->balanceOf($this->productB));
        $this->assertRejected(self::CHECK, fn () => $this->adjustmentItem($adjustmentId, 'extraordinary_entry', $this->productA, '1.000'), 'agregar línea a un ajuste anulado');
        $this->assertRejected(self::CHECK, fn () => DB::table('inventory_adjustments')->where('id', $adjustmentId)->update(['state' => 'applied', 'voided_by' => null, 'voided_at' => null, 'void_reason' => null]), 'reaplicar un ajuste anulado');
    }

    public function test_an_adjustment_idempotency_key_is_unique_per_user(): void
    {
        $this->adjustment('opening', 'K1', 'Motivo de prueba', ['applied_idempotency_key' => 'k-1']);

        $this->assertRejected(self::UNIQUE, fn () => $this->adjustment('opening', 'K2', 'Motivo de prueba', ['applied_idempotency_key' => 'k-1']), 'misma clave del mismo usuario');
    }

    /** Fuerza la evaluación de las comprobaciones DEFERRED que PostgreSQL haría al confirmar. */
    private function settle(): void
    {
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        DB::statement('SET CONSTRAINTS ALL DEFERRED');
    }

    /** Ejecuta dentro de un savepoint y fuerza la conciliación; PostgreSQL debe rechazar el conjunto. */
    private function assertRejected(string $sqlState, callable $operation, string $message): void
    {
        try {
            DB::transaction(function () use ($operation) {
                $operation();
                $this->settle();
            });
        } catch (QueryException $exception) {
            $this->assertSame($sqlState, $exception->getCode(), $message.' (se esperaba SQLSTATE '.$sqlState.'): '.$exception->getMessage());

            return;
        }
        $this->fail($message.': PostgreSQL debió rechazar la operación.');
    }

    private function balanceOf(int $productId): string
    {
        return (string) DB::table('inventory_balances')->where('product_id', $productId)->where('warehouse_id', $this->warehouseId)->value('quantity');
    }

    private function setBalance(int $productId, string $quantity): void
    {
        DB::table('inventory_balances')->where('product_id', $productId)->where('warehouse_id', $this->warehouseId)->update(['quantity' => $quantity]);
    }

    private function adjustment(string $type, string $reference, string $reason = 'Motivo de prueba', array $extra = []): int
    {
        return DB::table('inventory_adjustments')->insertGetId(array_merge([
            'warehouse_id' => $this->warehouseId, 'type' => $type, 'adjustment_date' => '2026-10-22',
            'reason' => $reason, 'reference' => $reference, 'created_by' => $this->author->id,
        ], $extra));
    }

    private function adjustmentItem(int $adjustmentId, string $type, int $productId, string $delta, ?string $counted = null, ?string $recorded = null): int
    {
        return DB::table('inventory_adjustment_items')->insertGetId([
            'adjustment_id' => $adjustmentId, 'adjustment_type' => $type, 'product_id' => $productId, 'unit_id' => $this->unitId,
            'product_sku' => 'SKU-'.$productId, 'product_name' => 'Producto '.$productId,
            'counted_quantity' => $counted, 'recorded_quantity' => $recorded, 'quantity_delta' => $delta,
        ]);
    }

    private function movement(array $attributes): int
    {
        return DB::table('inventory_movements')->insertGetId(array_merge([
            'warehouse_id' => $this->warehouseId, 'actor_id' => $this->author->id,
        ], $attributes));
    }

    /** Hace lo que hará el servicio: cabecera + línea + movimiento + saldo, todo en la misma transacción. */
    private function applyEntry(string $type, string $reference, int $productId, string $quantity): array
    {
        $before = $this->balanceOf($productId);
        $after = bcadd($before, $quantity, 3);
        $adjustmentId = $this->adjustment($type, $reference);
        $itemId = $this->adjustmentItem($adjustmentId, $type, $productId, $quantity);
        $movementId = $this->movement([
            'product_id' => $productId, 'movement_type' => $type, 'quantity' => $quantity,
            'balance_before' => $before, 'balance_after' => $after, 'adjustment_item_id' => $itemId, 'reason' => 'Motivo de prueba',
        ]);
        $this->setBalance($productId, $after);

        return [$adjustmentId, $itemId, $movementId];
    }

    private function applyCount(string $reference, int $productId, string $counted): void
    {
        $recorded = $this->balanceOf($productId);
        $delta = bcsub($counted, $recorded, 3);
        $adjustmentId = $this->adjustment('count_adjustment', $reference, 'Conteo');
        $itemId = $this->adjustmentItem($adjustmentId, 'count_adjustment', $productId, $delta, $counted, $recorded);
        $this->movement([
            'product_id' => $productId, 'movement_type' => 'count_adjustment', 'quantity' => $delta,
            'balance_before' => $recorded, 'balance_after' => $counted, 'adjustment_item_id' => $itemId, 'reason' => 'Conteo',
        ]);
        $this->setBalance($productId, $counted);
    }

    /** @param list<array{0:int,1:string,2:string}> $lines producto, cantidad, costo */
    private function purchase(string $reference, array $lines): int
    {
        $purchaseId = DB::table('purchases')->insertGetId([
            'supplier_id' => $this->supplierId, 'warehouse_id' => $this->warehouseId, 'document_date' => '2026-10-22',
            'supplier_reference' => $reference, 'created_by' => $this->author->id,
        ]);
        foreach ($lines as [$productId, $quantity, $cost]) {
            $this->purchaseLine($purchaseId, $productId, $quantity, $cost);
        }

        return $purchaseId;
    }

    private function purchaseLine(int $purchaseId, int $productId, string $quantity, string $cost): int
    {
        return DB::table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId, 'product_id' => $productId, 'unit_id' => $this->unitId,
            'product_sku' => 'SKU-'.$productId, 'product_name' => 'Producto '.$productId,
            'quantity' => $quantity, 'unit_cost' => $cost, 'line_total' => app(DecimalService::class)->lineTotal($quantity, $cost),
        ]);
    }

    private function draftPurchaseItem(int $productId, string $quantity, string $cost): int
    {
        $purchaseId = $this->purchase('D'.DB::table('purchases')->count(), []);

        return $this->purchaseLine($purchaseId, $productId, $quantity, $cost);
    }

    private function confirmOnly(int $purchaseId): void
    {
        $lines = DB::table('purchase_items')->where('purchase_id', $purchaseId)->pluck('line_total')->map(fn ($value) => (string) $value)->all();
        DB::table('purchases')->where('id', $purchaseId)->update([
            'state' => 'confirmed', 'total' => app(DecimalService::class)->total($lines),
            'confirmed_by' => $this->author->id, 'confirmed_at' => now(),
        ]);
    }

    /** Hace lo que hará ConfirmarCompra: una entrada por línea, saldo y estado en la misma transacción. */
    private function receive(int $purchaseId, ?string $forcedQuantity = null): void
    {
        foreach (DB::table('purchase_items')->where('purchase_id', $purchaseId)->orderBy('id')->get() as $item) {
            $quantity = $forcedQuantity ?? $item->quantity;
            $before = $this->balanceOf($item->product_id);
            $after = bcadd($before, $quantity, 3);
            $this->movement([
                'product_id' => $item->product_id, 'movement_type' => 'purchase', 'quantity' => $quantity,
                'balance_before' => $before, 'balance_after' => $after, 'purchase_item_id' => $item->id,
            ]);
            $this->setBalance($item->product_id, $after);
        }
        $this->confirmOnly($purchaseId);
    }

    private function voidOnly(int $purchaseId): void
    {
        DB::table('purchases')->where('id', $purchaseId)->update([
            'state' => 'voided', 'voided_by' => $this->reviewer->id, 'voided_at' => now(), 'void_reason' => 'Error de captura',
        ]);
    }

    private function reverseItem(int $purchaseId, int $productId, string $quantity): void
    {
        $itemId = (int) DB::table('purchase_items')->where('purchase_id', $purchaseId)->where('product_id', $productId)->value('id');
        $originalId = (int) DB::table('inventory_movements')->where('purchase_item_id', $itemId)->whereNull('reversal_of_id')->value('id');
        $before = $this->balanceOf($productId);
        $this->movement([
            'product_id' => $productId, 'movement_type' => 'reversal', 'quantity' => $quantity,
            'balance_before' => $before, 'balance_after' => bcadd($before, $quantity, 3),
            'purchase_item_id' => $itemId, 'reversal_of_id' => $originalId, 'actor_id' => $this->reviewer->id, 'reason' => 'Anulación',
        ]);
    }

    /** Hace lo que hará AnularCompra: una reversión por entrada, saldos y estado en la misma transacción. */
    private function voidWithReversals(int $purchaseId): void
    {
        $this->voidOnly($purchaseId);
        foreach (DB::table('purchase_items')->where('purchase_id', $purchaseId)->orderBy('id')->get() as $item) {
            $original = DB::table('inventory_movements')->where('purchase_item_id', $item->id)->whereNull('reversal_of_id')->first();
            $quantity = bcmul((string) $original->quantity, '-1', 3);
            $this->reverseItem($purchaseId, $item->product_id, $quantity);
            $this->setBalance($item->product_id, bcadd($this->balanceOf($item->product_id), $quantity, 3));
        }
    }

    private function voidAdjustmentOnly(int $adjustmentId): void
    {
        DB::table('inventory_adjustments')->where('id', $adjustmentId)->update([
            'state' => 'voided', 'voided_by' => $this->reviewer->id, 'voided_at' => now(), 'void_reason' => 'Error de captura',
        ]);
    }

    private function actor(string $role, string $suffix): User
    {
        $user = new User;
        $user->forceFill([
            'name' => 'Usuario de pruebas '.$suffix,
            'email' => 'inventory-schema-'.$suffix.'@example.test',
            'password' => 'Fixture-only-passphrase-2026!',
            'role_id' => Role::where('code', $role)->sole()->id,
            'active' => true,
        ])->save();

        return $user;
    }
}
