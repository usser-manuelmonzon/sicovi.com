<?php
namespace App\Services;
use App\Models\Customer;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SaleService
{
    public function __construct(
        private DecimalService $decimals,
        private AuditService $audit,
        private InventoryService $inventory,
        private OrganizationContext $organization,
    ) {}

    /**
     * Crea un borrador y sus líneas dentro de una transacción.
     *
     * $data: customer_id, document_date, notes.
     * $lines: lista de product_id y quantity.
     */
    public function createDraft(
        User $actor,
        array $data,
        array $lines = [],
    ): Sale {
        $normalized = $this->normalizeRequest($lines);

        return DB::transaction(function () use (
            $actor,
            $data,
            $normalized
        ): Sale {
            $customerId = $this->validateCustomer(
                $data['customer_id'] ?? null
            );

            $warehouse = $this->organization->warehouse();

            $sale = new Sale();
            $sale->forceFill([
                'customer_id' => $customerId,
                'warehouse_id' => $warehouse->id,
                'document_date' => $data['document_date']
                    ?? now()->toDateString(),
                'notes' => $this->nullableText($data['notes'] ?? null),
                'created_by' => $actor->id,
                'state' => 'draft',
                'total' => '0.00',
            ])->saveOrFail();

            $total = $this->saveDraftLines($sale, $normalized);

            $sale->forceFill(['total' => $total])->saveOrFail();

            $this->audit->record(
                $actor,
                'sales.created',
                $sale,
                [],
                $sale->getAttributes(),
            );

            return $sale->fresh(['items', 'customer', 'warehouse']);
        }, 3);
    }

    /**
     * Solo permite reemplazar los datos y las líneas de un borrador.
     */
    public function updateDraft(
        Sale $sale,
        User $actor,
        array $data,
        array $lines,
    ): Sale {
        $normalized = $this->normalizeRequest($lines);

        return DB::transaction(function () use (
            $sale,
            $actor,
            $data,
            $normalized
        ): Sale {
            $locked = Sale::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($locked->state !== 'draft') {
                throw ValidationException::withMessages([
                    'sale' => 'Solo se pueden editar ventas en borrador.',
                ]);
            }

            $customerId = $this->validateCustomer(
                $data['customer_id'] ?? null
            );

            $before = $locked->getAttributes();

            $locked->forceFill([
                'customer_id' => $customerId,
                'document_date' => $data['document_date']
                    ?? $locked->document_date,
                'notes' => $this->nullableText(
                    $data['notes'] ?? null
                ),
            ])->saveOrFail();

            $locked->items()->delete();

            $total = $this->saveDraftLines($locked, $normalized);

            $locked->forceFill(['total' => $total])->saveOrFail();

            $this->audit->record(
                $actor,
                'sales.updated',
                $locked,
                $before,
                $locked->getAttributes(),
            );

            return $locked->fresh(['items', 'customer', 'warehouse']);
        }, 3);
    }

    /**
     * Confirma un borrador, calcula los precios en el servidor
     * y descuenta inventario una sola vez.
     *
     * $lines: lista de product_id y quantity.
     */
    public function confirmSale(
        Sale $sale,
        array $lines,
        User $actor,
        string $idempotencyKey,
    ): Sale {
        $idempotencyKey = trim($idempotencyKey);

        if (
            $idempotencyKey === ''
            || mb_strlen($idempotencyKey) > 80
        ) {
            throw ValidationException::withMessages([
                'idempotency_key' =>
                    'La clave debe tener entre 1 y 80 caracteres.',
            ]);
        }

        $normalized = $this->normalizeRequest($lines);

        if ($normalized === []) {
            throw ValidationException::withMessages([
                'lines' => 'La venta debe contener al menos un producto.',
            ]);
        }

        $hash = hash('sha256', json_encode([
            'sale_id' => $sale->id,
            'lines' => $normalized,
        ], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use (
            $sale,
            $actor,
            $normalized,
            $idempotencyKey,
            $hash
        ): Sale {
            $locked = Sale::query()
                ->whereKey($sale->id)
                ->lockForUpdate()
                ->firstOrFail();

            $existing = DB::table('idempotency_records')
                ->where('user_id', $actor->id)
                ->where('action', 'sales.confirm')
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if (! hash_equals($existing->request_hash, $hash)) {
                    throw ValidationException::withMessages([
                        'idempotency_key' =>
                            'La clave ya se utilizó con otros datos.',
                    ]);
                }

                return Sale::query()->findOrFail($existing->result_id);
            }

            if ($locked->state !== 'draft') {
                throw ValidationException::withMessages([
                    'sale' =>
                        'Solo se pueden confirmar ventas en borrador.',
                ]);
            }

            $this->validateCustomer($locked->customer_id);

            $items = $locked->items()
                ->lockForUpdate()
                ->get()
                ->keyBy('product_id');

            $existingProductIds = $items->keys()
                ->map(fn ($id) => (int) $id)
                ->sort()
                ->values()
                ->all();

            if (
                $existingProductIds !== array_keys($normalized)
                || $items->isEmpty()
            ) {
                throw ValidationException::withMessages([
                    'lines' =>
                        'Las líneas enviadas no coinciden con el borrador.',
                ]);
            }

            $lineTotals = [];
            $inventoryLines = [];

            foreach ($normalized as $productId => $line) {
                $item = $items->get($productId);
                $product = $this->productForSale($productId);

                try {
                    $quantity = $this->decimals->quantity(
                        $line['quantity'],
                        (int) $product->decimal_places,
                        true,
                    );

                    $unitPrice = $this->decimals->money(
                        (string) $product->sale_price
                    );

                    if (bccomp($unitPrice, '0', 2) < 0) {
                        throw new \InvalidArgumentException(
                            'El precio de venta no puede ser negativo.'
                        );
                    }

                    $lineTotal = $this->decimals->lineTotal(
                        $quantity,
                        $unitPrice,
                    );
                } catch (\InvalidArgumentException $e) {
                    throw ValidationException::withMessages([
                        'lines' => $e->getMessage(),
                    ]);
                }

                $item->forceFill([
                    'unit_id' => $product->unit_id,
                    'product_sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $lineTotal,
                ])->saveOrFail();

                $lineTotals[] = $lineTotal;

                $inventoryLines[] = [
                    'sale_item_id' => $item->id,
                    'product_id' => $productId,
                    'quantity' => $quantity,
                ];
            }

            $total = $this->decimals->total($lineTotals);
            $before = $locked->getAttributes();

            // El movimiento de inventario requiere que la venta
            // y sus líneas ya estén en estado confirmado.
            $locked->forceFill([
                'state' => 'confirmed',
                'total' => $total,
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'confirmation_idempotency_key' => $idempotencyKey,
            ])->saveOrFail();

            $this->inventory->issueSaleLines(
                $actor,
                $locked->warehouse_id,
                $inventoryLines,
            );

            $this->audit->record(
                $actor,
                'sales.confirmed',
                $locked,
                $before,
                $locked->getAttributes(),
                'Venta confirmada.',
            );

            DB::table('idempotency_records')->insert([
                'user_id' => $actor->id,
                'action' => 'sales.confirm',
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $hash,
                'result_type' => Sale::class,
                'result_id' => $locked->id,
                'created_at' => now(),
            ]);

            return $locked->fresh([
                'items',
                'customer',
                'warehouse',
                'creator',
                'confirmer',
            ]);
        }, 3);
    }

    /**
     * Guarda líneas de un borrador usando precios del catálogo.
     * Los precios enviados por el navegador nunca se aceptan.
     */
    private function saveDraftLines(
        Sale $sale,
        array $lines,
    ): string {
        $totals = [];

        foreach ($lines as $productId => $line) {
            $product = $this->productForSale($productId);

            try {
                $quantity = $this->decimals->quantity(
                    $line['quantity'],
                    (int) $product->decimal_places,
                    true,
                );

                $unitPrice = $this->decimals->money(
                    (string) $product->sale_price
                );

                if (bccomp($unitPrice, '0', 2) < 0) {
                    throw new \InvalidArgumentException(
                        'El precio de venta no puede ser negativo.'
                    );
                }

                $lineTotal = $this->decimals->lineTotal(
                    $quantity,
                    $unitPrice,
                );
            } catch (\InvalidArgumentException $e) {
                throw ValidationException::withMessages([
                    'lines' => $e->getMessage(),
                ]);
            }

            $sale->items()->create([
                'product_id' => $productId,
                'unit_id' => $product->unit_id,
                'product_sku' => $product->sku,
                'product_name' => $product->name,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
            ]);

            $totals[] = $lineTotal;
        }

        return $this->decimals->total($totals);
    }

    /**
     * Normaliza líneas, rechaza duplicados y ordena por producto.
     */
    private function normalizeRequest(array $lines): array
    {
        $normalized = [];

        foreach ($lines as $line) {
            $productId = filter_var(
                $line['product_id'] ?? null,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );

            $quantity = $line['quantity'] ?? null;

            if (
                $productId === false
                || ! is_string($quantity)
                && ! is_int($quantity)
                && ! is_float($quantity)
            ) {
                throw ValidationException::withMessages([
                    'lines' => 'Hay líneas de venta inválidas.',
                ]);
            }

            if (isset($normalized[$productId])) {
                throw ValidationException::withMessages([
                    'lines' =>
                        'No repitas productos en una misma venta.',
                ]);
            }

            $normalized[$productId] = [
                'quantity' => (string) $quantity,
            ];
        }

        ksort($normalized, SORT_NUMERIC);

        return $normalized;
    }

    private function productForSale(int $productId): object
    {
        $product = DB::table('products')
            ->join('units', 'units.id', '=', 'products.unit_id')
            ->where('products.id', $productId)
            ->where('products.active', true)
            ->where('units.active', true)
            ->first([
                'products.id',
                'products.sku',
                'products.name',
                'products.unit_id',
                'products.sale_price',
                'units.decimal_places',
            ]);

        if (! $product) {
            throw ValidationException::withMessages([
                'lines' =>
                    "El producto {$productId} no existe o está inactivo.",
            ]);
        }

        return $product;
    }

    private function validateCustomer(mixed $customerId): ?int
    {
        if ($customerId === null || $customerId === '') {
            return null;
        }

        $id = filter_var(
            $customerId,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($id === false) {
            throw ValidationException::withMessages([
                'customer_id' => 'Selecciona un cliente válido.',
            ]);
        }

        $customer = Customer::query()
            ->whereKey($id)
            ->lockForUpdate()
            ->first();

        if (! $customer || ! $customer->active) {
            throw ValidationException::withMessages([
                'customer_id' =>
                    'El cliente no existe o está inactivo.',
            ]);
        }

        return (int) $customer->id;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }
}