<?php

namespace App\Services;

use App\Models\InventoryAdjustment;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Unico punto que mueve stock (contrato YOURGUEN-DB-002, seccion 7).
 * Saldo y movimiento se escriben siempre juntos y dentro de la misma transaccion;
 * PostgreSQL verifica la conciliacion al COMMIT.
 */
class InventoryService
{
    public const PERMISSION_ADJUST = 'inventory.adjust';

    public const PERMISSION_VOID_ADJUSTMENT = 'inventory.adjust';

    private const TYPES = ['opening', 'extraordinary_entry', 'count_adjustment'];

    public function __construct(
        private AuditService $audit,
        private DecimalService $decimals,
        private OrganizationContext $organization,
    ) {}

    public function applyAdjustment(User $actor, array $data): InventoryAdjustment
    {
        try {
            return $this->applyAdjustmentOnce($actor, $data);
        } catch (UniqueConstraintViolationException $e) {
            $key = trim((string) ($data['idempotency_key'] ?? ''));
            $existing = $key !== '' ? InventoryAdjustment::where('created_by', $actor->id)->where('applied_idempotency_key', $key)->first() : null;
            if ($existing) {
                return $existing;
            }
            throw $e;
        }
    }

    /**
     * @param  array{type:string,adjustment_date:string,reason:string,reference?:?string,notes?:?string,idempotency_key?:?string,lines:list<array{product_id:int,quantity?:string,counted_quantity?:string}>}  $data
     */
    private function applyAdjustmentOnce(User $actor, array $data): InventoryAdjustment
    {
        return DB::transaction(function () use ($actor, $data): InventoryAdjustment {
            Gate::forUser($actor)->authorize(self::PERMISSION_ADJUST);

            $type = $data['type'] ?? '';
            if (! in_array($type, self::TYPES, true)) {
                $this->invalid('type', 'Seleccione un tipo de ajuste válido.');
            }
            $reason = $this->text($data['reason'] ?? null, 'reason', 'Indique el motivo del ajuste.');
            $reference = isset($data['reference']) && trim((string) $data['reference']) !== '' ? trim($data['reference']) : null;
            $notes = isset($data['notes']) && trim((string) $data['notes']) !== '' ? trim($data['notes']) : null;
            $key = isset($data['idempotency_key']) && trim((string) $data['idempotency_key']) !== '' ? trim($data['idempotency_key']) : null;
            $raw = (string) ($data['adjustment_date'] ?? '');
            try {
                $date = Carbon::createFromFormat('!Y-m-d', $raw);
            } catch (\Throwable) {
                $date = null;
            }
            if (! $date || $date->format('Y-m-d') !== $raw) {
                $this->invalid('adjustment_date', 'Ingrese una fecha válida (AAAA-MM-DD).');
            }

            if ($key !== null) {
                $existing = InventoryAdjustment::where('created_by', $actor->id)->where('applied_idempotency_key', $key)->first();
                if ($existing) {
                    return $existing; // reintento: no se duplica
                }
            }

            $warehouse = $this->organization->warehouse();
            $lines = $this->normalizeLines($type, $data['lines'] ?? []);
            $products = DB::table('products')->join('units', 'units.id', '=', 'products.unit_id')
                ->whereIn('products.id', array_keys($lines))
                ->get(['products.id', 'products.sku', 'products.name', 'products.active', 'products.unit_id', 'units.decimal_places'])
                ->keyBy('id');
            if ($products->count() !== count($lines)) {
                $this->invalid('lines', 'Hay productos que no existen.');
            }

            // 1. Bloqueo de saldos en orden ascendente de product_id (contrato, paso 1).
            $balances = $this->lockBalances($warehouse->id, array_keys($lines));

            // 2. Calculo de deltas y validacion por linea.
            $items = [];
            foreach ($lines as $productId => $line) {
                $product = $products[$productId];
                if (! $product->active) {
                    $this->invalid('lines', "El producto {$product->sku} está inactivo.");
                }
                try {
                    if ($type === 'count_adjustment') {
                        $counted = $this->decimals->quantity($line['counted_quantity'], $product->decimal_places);
                        $recorded = $balances[$productId];
                        $delta = bcsub($counted, $recorded, 3);
                        if (bccomp($delta, '0', 3) === 0) {
                            continue; // sin diferencia: no genera linea ni movimiento
                        }
                    } else {
                        $counted = $recorded = null;
                        $delta = $this->decimals->quantity($line['quantity'], $product->decimal_places, true);
                    }
                } catch (\InvalidArgumentException $e) {
                    $this->invalid('lines', "{$product->sku}: ".$e->getMessage());
                }
                $items[] = compact('product', 'counted', 'recorded', 'delta');
            }
            if ($items === []) {
                $this->invalid('lines', $type === 'count_adjustment' ? 'El conteo coincide con el saldo registrado; no hay nada que ajustar.' : 'Agregue al menos una línea.');
            }

            // 3. Cabecera, lineas y movimientos.
            $adjustment = InventoryAdjustment::create([
                'warehouse_id' => $warehouse->id, 'type' => $type, 'adjustment_date' => $date->toDateString(),
                'reason' => $reason, 'reference' => $reference, 'notes' => $notes,
                'created_by' => $actor->id, 'applied_idempotency_key' => $key,
            ]);
            $adjustment->refresh(); // trae code (AJU-000001) generado por la BD

            $entries = [];
            foreach ($items as $item) {
                $itemId = DB::table('inventory_adjustment_items')->insertGetId([
                    'adjustment_id' => $adjustment->id, 'adjustment_type' => $type,
                    'product_id' => $item['product']->id, 'unit_id' => $item['product']->unit_id,
                    'product_sku' => $item['product']->sku, 'product_name' => $item['product']->name,
                    'counted_quantity' => $item['counted'], 'recorded_quantity' => $item['recorded'],
                    'quantity_delta' => $item['delta'], 'created_at' => now(), 'updated_at' => now(),
                ]);
                $entries[] = [
                    'product_id' => $item['product']->id, 'movement_type' => $type, 'quantity' => $item['delta'],
                    'adjustment_item_id' => $itemId, 'reason' => $reason,
                ];
            }
            $this->post($actor, $warehouse->id, $entries);

            $this->audit->record($actor, 'inventory_adjustments.applied', $adjustment, [], $adjustment->getAttributes(), $reason);

            return $adjustment;
        });
    }

    public function voidAdjustment(User $actor, InventoryAdjustment $adjustment, string $reason): InventoryAdjustment
    {
        return DB::transaction(function () use ($actor, $adjustment, $reason): InventoryAdjustment {
            Gate::forUser($actor)->authorize(self::PERMISSION_VOID_ADJUSTMENT);
            $reason = $this->text($reason, 'reason', 'Indique el motivo de la anulación.');
            $adjustment = InventoryAdjustment::whereKey($adjustment->id)->lockForUpdate()->firstOrFail();
            if ($adjustment->state !== 'applied') {
                $this->invalid('state', 'El ajuste ya está anulado.');
            }
            $before = $adjustment->getAttributes();

            // El documento pasa a voided ANTES de insertar las reversiones (lo exige origin_guard).
            $adjustment->forceFill(['state' => 'voided', 'voided_by' => $actor->id, 'voided_at' => now(), 'void_reason' => $reason])->save();

            $originals = DB::table('inventory_movements as m')
                ->join('inventory_adjustment_items as i', 'i.id', '=', 'm.adjustment_item_id')
                ->where('i.adjustment_id', $adjustment->id)->whereNull('m.reversal_of_id')
                ->orderBy('m.product_id')->orderBy('m.id')
                ->get(['m.id', 'm.product_id', 'm.quantity', 'm.adjustment_item_id']);
            $this->post($actor, $adjustment->warehouse_id, $this->reversalEntries($originals, 'adjustment_item_id', $reason));

            $this->audit->record($actor, 'inventory_adjustments.voided', $adjustment, $before, $adjustment->getAttributes(), $reason);

            return $adjustment;
        });
    }

    /**
     * Para PurchaseService, dentro de SU transaccion: una entrada por linea al confirmar.
     * La autorizacion de la compra es responsabilidad del llamador.
     *
     * @param  list<array{purchase_item_id:int,product_id:int,quantity:string}>  $lines
     */
    public function receivePurchaseLines(User $actor, int $warehouseId, array $lines): void
    {
        $places = DB::table('products')->join('units', 'units.id', '=', 'products.unit_id')
            ->whereIn('products.id', array_column($lines, 'product_id'))
            ->pluck('units.decimal_places', 'products.id');
        $entries = [];
        foreach ($lines as $line) {
            if (! isset($places[$line['product_id']])) {
                $this->invalid('lines', 'Hay productos que no existen.');
            }
            try {
                $quantity = $this->decimals->quantity($line['quantity'], (int) $places[$line['product_id']], true);
            } catch (\InvalidArgumentException $e) {
                $this->invalid('lines', $e->getMessage());
            }
            $entries[] = [
                'product_id' => (int) $line['product_id'], 'movement_type' => 'purchase', 'quantity' => $quantity,
                'purchase_item_id' => (int) $line['purchase_item_id'], 'reason' => null,
            ];
        }
        usort($entries, fn ($a, $b) => $a['product_id'] <=> $b['product_id']);
        $this->post($actor, $warehouseId, $entries);
    }

    /**
     * Para PurchaseService al anular: la compra debe estar ya marcada 'voided' en esta transaccion.
     *
     * @param  list<int>  $purchaseItemIds
     */
    public function reversePurchaseLines(User $actor, int $warehouseId, array $purchaseItemIds, string $reason): void
    {
        $reason = $this->text($reason, 'reason', 'Indique el motivo de la anulación.');
        $originals = DB::table('inventory_movements')
            ->whereIn('purchase_item_id', $purchaseItemIds)->whereNull('reversal_of_id')
            ->orderBy('product_id')->orderBy('id')
            ->get(['id', 'product_id', 'quantity', 'purchase_item_id']);
        if ($originals->count() !== count(array_unique($purchaseItemIds))) {
            $this->invalid('lines', 'Faltan movimientos de entrada que revertir.');
        }
        $this->post($actor, $warehouseId, $this->reversalEntries($originals, 'purchase_item_id', $reason));
    }

    /** Nucleo: inserta movimientos y actualiza saldos en conjunto. */
    private function post(User $actor, int $warehouseId, array $entries): void
    {
        if (DB::transactionLevel() < 1) {
            throw new LogicException('Los movimientos de inventario deben registrarse dentro de una transacción.');
        }
        $balances = $this->lockBalances($warehouseId, array_column($entries, 'product_id'));
        $changed = [];
        foreach ($entries as $entry) {
            $productId = $entry['product_id'];
            $before = $balances[$productId];
            $after = bcadd($before, $entry['quantity'], 3);
            if (bccomp($after, '0', 3) < 0) {
                $this->invalid('lines', "Saldo insuficiente para el producto {$productId}: disponible {$before}.");
            }
            DB::table('inventory_movements')->insert([
                'product_id' => $productId, 'warehouse_id' => $warehouseId, 'movement_type' => $entry['movement_type'],
                'quantity' => $entry['quantity'], 'balance_before' => $before, 'balance_after' => $after,
                'purchase_item_id' => $entry['purchase_item_id'] ?? null,
                'adjustment_item_id' => $entry['adjustment_item_id'] ?? null,
                'reversal_of_id' => $entry['reversal_of_id'] ?? null,
                'actor_id' => $actor->id, 'reason' => $entry['reason'] ?? null,
            ]);
            $balances[$productId] = $after;
            $changed[$productId] = true;
        }
        foreach (array_keys($changed) as $productId) {
            DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->where('product_id', $productId)
                ->update(['quantity' => $balances[$productId], 'updated_at' => now()]);
        }
    }

    /** @return array<int,string> product_id => saldo (3 decimales), bloqueado en orden ascendente. */
    private function lockBalances(int $warehouseId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        sort($productIds);
        $rows = DB::table('inventory_balances')->where('warehouse_id', $warehouseId)->whereIn('product_id', $productIds)
            ->orderBy('product_id')->lockForUpdate()->pluck('quantity', 'product_id');
        $balances = [];
        foreach ($productIds as $productId) {
            if (! isset($rows[$productId])) {
                $this->invalid('lines', "No existe saldo para el producto {$productId} en la bodega.");
            }
            $balances[$productId] = bcadd((string) $rows[$productId], '0', 3);
        }

        return $balances;
    }

    private function reversalEntries($originals, string $originColumn, string $reason): array
    {
        $entries = [];
        foreach ($originals as $original) {
            $entries[] = [
                'product_id' => (int) $original->product_id, 'movement_type' => 'reversal',
                'quantity' => bcmul((string) $original->quantity, '-1', 3),
                $originColumn => (int) $original->{$originColumn}, 'reversal_of_id' => (int) $original->id, 'reason' => $reason,
            ];
        }

        return $entries;
    }

    /** @return array<int,array> indexado por product_id y ordenado ascendente */
    private function normalizeLines(string $type, mixed $lines): array
    {
        if (! is_array($lines) || $lines === []) {
            $this->invalid('lines', 'Agregue al menos una línea.');
        }
        $field = $type === 'count_adjustment' ? 'counted_quantity' : 'quantity';
        $result = [];
        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            if (! is_int($productId) && ! (is_string($productId) && ctype_digit($productId))) {
                $this->invalid('lines', 'Producto no válido.');
            }
            $productId = (int) $productId;
            if (! isset($line[$field]) || trim((string) $line[$field]) === '') {
                continue;
            }
            if (isset($result[$productId])) {
                $this->invalid('lines', 'Un producto no puede repetirse en el mismo ajuste.');
            }
            if (! is_string($line[$field])) {
                $this->invalid('lines', 'Cada línea debe traer su cantidad como texto decimal.');
            }
            $result[$productId] = $line;
        }
        ksort($result);

        return $result;
    }

    private function text(mixed $value, string $field, string $message): string
    {
        if (! is_string($value) || trim($value) === '') {
            $this->invalid($field, $message);
        }

        return trim($value);
    }

    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
