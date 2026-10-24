<?php

namespace App\Services;

use App\Models\Purchase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PurchaseService
{
    public function __construct(
        private DecimalService $decimals,
        private AuditService $audit,
        private InventoryService $inventory,
        private OrganizationContext $organization,
    ) {}

    /**
     * Confirms a draft and receives every line atomically.
     *
     * The request key and normalized payload are stored in idempotency_records.
     * Repeating an identical request returns the same purchase without posting
     * another inventory movement.
     */
    public function confirmPurchase(Purchase $purchase, array $lines, User $actor, string $idempotencyKey): Purchase
    {
        $normalized = $this->normalizeRequest($lines);
        $hash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($purchase, $normalized, $actor, $idempotencyKey, $hash): Purchase {
            $existingRequest = DB::table('idempotency_records')
                ->where('user_id', $actor->id)
                ->where('action', 'purchase.confirm')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingRequest) {
                if (! hash_equals($existingRequest->request_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'La clave de confirmación ya fue usada con datos diferentes.']);
                }

                return Purchase::query()->findOrFail($existingRequest->result_id);
            }

            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== 'draft') {
                throw ValidationException::withMessages(['purchase' => 'Solo se pueden confirmar compras en borrador.']);
            }

            $warehouseId = $locked->warehouse_id;
            $items = $locked->items()->lockForUpdate()->get()->keyBy('product_id');
            if ($items->count() !== count($normalized) || $items->keys()->sort()->values()->all() !== array_keys($normalized)) {
                throw ValidationException::withMessages(['lines' => 'Las líneas enviadas no coinciden con el borrador.']);
            }

            $lineTotals = [];
            $inventoryLines = [];
            foreach ($normalized as $productId => $line) {
                $item = $items->get($productId);
                $product = DB::table('products')
                    ->join('units', 'units.id', '=', 'products.unit_id')
                    ->where('products.id', $productId)
                    ->first(['products.id', 'products.active', 'products.unit_id', 'products.sku', 'products.name', 'units.decimal_places']);

                if (! $product || ! $product->active) {
                    throw ValidationException::withMessages(['lines' => "El producto {$productId} no existe o está inactivo."]);
                }

                try {
                    $quantity = $this->decimals->quantity($line['quantity'], (int) $product->decimal_places, true);
                    $unitCost = $this->decimals->money($line['unit_cost']);
                    $lineTotal = $this->decimals->lineTotal($quantity, $unitCost);
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages(['lines' => $e->getMessage()]);
                }

                $item->forceFill([
                    'unit_id' => $product->unit_id,
                    'product_sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'unit_cost' => $unitCost,
                    'line_total' => $lineTotal,
                ])->save();

                $lineTotals[] = $lineTotal;
                $inventoryLines[] = [
                    'purchase_item_id' => $item->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ];
            }

            $total = $this->decimals->total($lineTotals);
            $before = $locked->getAttributes();
            $locked->forceFill([
                'state' => 'confirmed',
                'total' => $total,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'confirmation_idempotency_key' => $idempotencyKey,
            ])->save();

            $this->inventory->receivePurchaseLines($actor, $warehouseId, $inventoryLines);
            $this->audit->record($actor, 'purchases.confirmed', $locked, $before, $locked->getAttributes(), 'Recepción de compra confirmada.');

            DB::table('idempotency_records')->insert([
                'user_id' => $actor->id,
                'action' => 'purchase.confirm',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'result_type' => Purchase::class,
                'result_id' => $locked->id,
                'created_at' => now(),
            ]);

            return $locked->fresh(['items.product', 'supplier', 'creator', 'confirmer']);
        }, 3);
    }

    public function voidPurchase(Purchase $purchase, User $actor, string $reason, string $idempotencyKey): Purchase
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'Indique el motivo de la anulación.']);
        }
        $hash = hash('sha256', json_encode(['purchase_id' => $purchase->id, 'reason' => $reason], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($purchase, $actor, $reason, $idempotencyKey, $hash): Purchase {
            $existingRequest = DB::table('idempotency_records')
                ->where('user_id', $actor->id)
                ->where('action', 'purchase.void')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingRequest) {
                if (! hash_equals($existingRequest->request_hash, $hash)) {
                    throw ValidationException::withMessages(['idempotency_key' => 'La clave de anulación ya fue usada con datos diferentes.']);
                }

                return Purchase::query()->findOrFail($existingRequest->result_id);
            }

            $locked = Purchase::query()->whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($locked->state === 'voided') {
                throw ValidationException::withMessages(['purchase' => 'La compra ya está anulada.']);
            }
            if ($locked->state !== 'confirmed') {
                throw ValidationException::withMessages(['purchase' => 'Solo se pueden anular compras confirmadas.']);
            }

            $before = $locked->getAttributes();
            $locked->forceFill([
                'state' => 'voided',
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => $reason,
            ])->save();

            $itemIds = $locked->items()->lockForUpdate()->pluck('id')->all();
            $this->inventory->reversePurchaseLines($actor, $locked->warehouse_id, $itemIds, $reason);
            $this->audit->record($actor, 'purchases.voided', $locked, $before, $locked->getAttributes(), $reason);

            DB::table('idempotency_records')->insert([
                'user_id' => $actor->id,
                'action' => 'purchase.void',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'result_type' => Purchase::class,
                'result_id' => $locked->id,
                'created_at' => now(),
            ]);

            return $locked->fresh(['items.product', 'supplier', 'creator', 'confirmer']);
        }, 3);
    }

    /** @return array<int,array{quantity:string,unit_cost:string}> */
    private function normalizeRequest(array $lines): array
    {
        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Agregue al menos una línea.']);
        }

        $normalized = [];
        foreach ($lines as $line) {
            $productId = $line['product_id'] ?? null;
            if (! is_numeric($productId) || (int) $productId < 1 || isset($normalized[(int) $productId])) {
                throw ValidationException::withMessages(['lines' => 'Cada producto debe ser válido y aparecer una sola vez.']);
            }
            $quantity = $line['quantity'] ?? null;
            $unitCost = $line['unit_cost'] ?? null;
            if (! is_string($quantity) || ! is_string($unitCost)) {
                throw ValidationException::withMessages(['lines' => 'Las cantidades y costos deben enviarse como decimales.']);
            }
            $normalized[(int) $productId] = ['quantity' => trim($quantity), 'unit_cost' => trim($unitCost)];
        }
        ksort($normalized);

        return $normalized;
    }
}
