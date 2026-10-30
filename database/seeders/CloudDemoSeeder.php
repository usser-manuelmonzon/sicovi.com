<?php

namespace Database\Seeders;

use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Subcategory;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\AuditService;
use App\Services\CatalogService;
use App\Services\CustomerService;
use App\Services\DecimalService;
use App\Services\InventoryService;
use App\Services\OrganizationContext;
use App\Services\PurchaseService;
use App\Services\SaleService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/** Explicit showcase data; never invoked by DatabaseSeeder or container startup. */
class CloudDemoSeeder extends Seeder
{
    public const KEY = 'sicovi-showcase-v1';

    private ?User $actor = null;

    public bool $alreadyLoaded = false;

    public function forAdministrator(User $actor): self
    {
        $this->actor = $actor;

        return $this;
    }

    public function run(): void
    {
        $actor = $this->actor?->fresh();
        if (! $actor || $actor->is_demo || ! $actor->active || ! $actor->role->active || $actor->role->code !== 'admin') {
            throw new RuntimeException('Use sicovi:cargar-ejemplo --admin=USUARIO --confirmar con un administrador real activo.');
        }
        foreach (['products.create', 'categories.manage', 'subcategories.manage', 'brands.manage', 'suppliers.create', 'customers.create', 'purchases.create', 'purchases.confirm', 'purchases.void', 'sales.create', 'sales.confirm', 'sales.void', 'inventory.adjust', 'inventory.void_adjustment'] as $permission) {
            Gate::forUser($actor)->authorize($permission);
        }

        DB::transaction(function () use ($actor): void {
            // Serialize this dataset globally, then follow the existing catalog lock order.
            DB::select('SELECT pg_advisory_xact_lock(74126901)');
            DB::select('SELECT pg_advisory_xact_lock(74126002)');
            $marker = DB::table('idempotency_records')->where('action', 'showcase.seed')->where('idempotency_key', self::KEY)->first();
            if ($marker) {
                $this->alreadyLoaded = true;

                return;
            }
            $tables = ['categories', 'subcategories', 'brands', 'products', 'suppliers', 'customers', 'purchases', 'purchase_items', 'sales', 'sale_items', 'inventory_balances', 'inventory_movements', 'inventory_adjustments', 'inventory_adjustment_items', 'idempotency_records'];
            // Prevent a concurrent first business write between the empty-data check and load.
            DB::statement('LOCK TABLE '.implode(', ', $tables).' IN SHARE ROW EXCLUSIVE MODE');
            foreach ($tables as $table) {
                if (DB::table($table)->exists()) {
                    throw new RuntimeException('Los ejemplos solo se cargan en una instalación sin catálogos ni operaciones. No se modificó información existente.');
                }
            }

            $catalogs = app(CatalogService::class);
            $food = $catalogs->saveCatalog('categories', new Category, ['code' => 'DEMO-ALIMENTOS', 'name' => 'Alimentos [DEMO]', 'active' => true], $actor);
            $office = $catalogs->saveCatalog('categories', new Category, ['code' => 'DEMO-HOGAR', 'name' => 'Hogar y oficina [DEMO]', 'active' => true], $actor);
            $sub = $catalogs->saveCatalog('subcategories', new Subcategory, ['category_id' => $food->id, 'name' => 'Abarrotes [DEMO]', 'active' => true], $actor);
            $brand = $catalogs->saveCatalog('brands', new Brand, ['name' => 'SICOVI Marca Ficticia [DEMO]', 'active' => true], $actor);
            $units = Unit::whereIn('code', ['UND', 'KG', 'LT'])->get()->keyBy('code');
            if ($units->count() !== 3) {
                throw new RuntimeException('Ejecute primero el procedimiento de migraciones y SystemSeeder.');
            }
            $products = [];
            foreach ([
                ['DEMO-ARROZ-KG', 'Arroz a granel [DEMO]', 'KG', '7.25', '10.50', 'Venta por kilogramo', $food->id],
                ['DEMO-CAFE-250G', 'Café de muestra [DEMO]', 'UND', '18.50', '27.00', 'Bolsa de 250 gramos', $food->id],
                ['DEMO-DETERGENTE-LT', 'Detergente de muestra [DEMO]', 'LT', '9.00', '14.00', 'Venta por litro', $office->id],
                ['DEMO-CUADERNO-100', 'Cuaderno de muestra [DEMO]', 'UND', '5.00', '8.50', 'Unidad de 100 hojas', $office->id],
            ] as [$sku, $name, $unit, $cost, $price, $presentation, $categoryId]) {
                $products[] = $catalogs->saveProduct(new Product, [
                    'sku' => $sku, 'name' => $name, 'category_id' => $categoryId,
                    'subcategory_id' => $categoryId === $food->id ? $sub->id : null,
                    'brand_id' => $brand->id, 'unit_id' => $units[$unit]->id,
                    'sale_price' => $price, 'reference_cost' => $cost, 'minimum_stock' => '5.000',
                    'presentation' => $presentation, 'active' => true,
                ], $actor);
            }
            $suppliers = [];
            for ($index = 1; $index <= 2; $index++) {
                $supplier = new Supplier;
                $supplier->fill([
                    'name' => 'Distribuidora Ficticia Guatemala '.$index.' [DEMO]', 'tax_id' => null,
                    'email' => 'proveedor'.$index.'@sicovi.invalid', 'address' => 'Dirección ficticia para demostración, Guatemala.',
                    'notes' => 'Identidad ficticia; no utilizar para transacciones reales.', 'active' => true,
                ])->save();
                $supplier->refresh();
                app(AuditService::class)->record($actor, 'suppliers.created', $supplier, [], $supplier->getAttributes());
                $suppliers[] = $supplier;
            }
            $customers = [];
            for ($index = 1; $index <= 3; $index++) {
                $customers[] = app(CustomerService::class)->create($actor, [
                    'name' => 'Cliente Ficticio '.$index.' [DEMO]', 'tax_id' => null,
                    'email' => 'cliente'.$index.'@sicovi.invalid', 'address' => 'Dirección ficticia, Guatemala.', 'active' => true,
                ]);
            }
            $today = now('America/Guatemala')->startOfDay();
            $purchase = $this->draftPurchase($actor, $suppliers[0]->id, $today->copy()->subDays(12)->toDateString(), 'DEMO-RECEPCION-01', $products, ['50', '40', '30', '50']);
            $this->confirmPurchase($actor, $purchase, 'demo-purchase-01');
            $second = $this->draftPurchase($actor, $suppliers[1]->id, $today->copy()->subDays(9)->toDateString(), 'DEMO-RECEPCION-02', [$products[1], $products[3]], ['20', '10']);
            $this->confirmPurchase($actor, $second, 'demo-purchase-02');
            $voided = $this->draftPurchase($actor, $suppliers[0]->id, $today->copy()->subDays(7)->toDateString(), 'DEMO-ANULADA-03', [$products[0]], ['10']);
            $voided = $this->confirmPurchase($actor, $voided, 'demo-purchase-03');
            app(PurchaseService::class)->voidPurchase($voided, $actor, 'Anulación ficticia para demostración.', 'demo-purchase-03-void');
            $this->draftPurchase($actor, $suppliers[1]->id, $today->toDateString(), 'DEMO-BORRADOR-04', [$products[0]], ['5']);

            $sales = app(SaleService::class);
            foreach ([
                [0, 6, [0 => '3.500', 1 => '4', 3 => '6']],
                [1, 3, [0 => '2', 1 => '3', 2 => '2.500']],
                [2, 1, [1 => '2', 3 => '1']],
            ] as $index => [$customerIndex, $daysAgo, $quantities]) {
                $lines = [];
                foreach ($quantities as $productIndex => $quantity) {
                    $lines[] = ['product_id' => $products[$productIndex]->id, 'quantity' => $quantity];
                }
                $sale = $sales->createDraft($actor, ['customer_id' => $customers[$customerIndex]->id, 'document_date' => $today->copy()->subDays($daysAgo)->toDateString(), 'notes' => 'Operación ficticia [DEMO].'], $lines);
                $sale = $sales->confirmSale($sale, $actor, 'demo-sale-'.($index + 1));
                if ($index === 2) {
                    $sales->voidSale($sale, $actor, 'Anulación ficticia para demostración.', 'demo-sale-03-void');
                }
            }
            $sales->createDraft($actor, ['customer_id' => $customers[0]->id, 'document_date' => $today->toDateString(), 'notes' => 'Borrador ficticio [DEMO].'], [['product_id' => $products[3]->id, 'quantity' => '1']]);
            $cancelled = $sales->createDraft($actor, ['customer_id' => $customers[1]->id, 'document_date' => $today->toDateString(), 'notes' => 'Borrador cancelado [DEMO].'], [['product_id' => $products[0]->id, 'quantity' => '1']]);
            $sales->cancelDraft($cancelled, $actor, 'Cancelación ficticia de un borrador.');

            $inventory = app(InventoryService::class);
            $base = ['adjustment_date' => $today->toDateString(), 'reason' => 'Ajuste autorizado ficticio [DEMO].'];
            $inventory->applyAdjustment($actor, $base + ['type' => 'extraordinary_entry', 'reference' => 'DEMO-AJUSTE-01', 'idempotency_key' => 'demo-adjustment-01', 'lines' => [['product_id' => $products[2]->id, 'quantity' => '1.500']]]);
            $inventory->applyAdjustment($actor, $base + ['type' => 'count_adjustment', 'reference' => 'DEMO-CONTEO-02', 'idempotency_key' => 'demo-adjustment-02', 'lines' => [['product_id' => $products[3]->id, 'counted_quantity' => '53']]]);
            $adjustment = $inventory->applyAdjustment($actor, $base + ['type' => 'extraordinary_entry', 'reference' => 'DEMO-ANULADO-03', 'idempotency_key' => 'demo-adjustment-03', 'lines' => [['product_id' => $products[1]->id, 'quantity' => '1']]]);
            $inventory->voidAdjustment($actor, $adjustment, 'Anulación ficticia de un ajuste.');

            DB::table('idempotency_records')->insert([
                'user_id' => $actor->id, 'action' => 'showcase.seed', 'idempotency_key' => self::KEY,
                'request_hash' => hash('sha256', self::KEY), 'result_type' => null, 'result_id' => null, 'created_at' => now(),
            ]);
        });
    }

    /** PurchaseService owns confirmation; no draft-creation service exists. */
    private function draftPurchase(User $actor, int $supplierId, string $date, string $reference, array $products, array $quantities): Purchase
    {
        $purchase = new Purchase;
        $purchase->forceFill([
            'supplier_id' => $supplierId, 'warehouse_id' => app(OrganizationContext::class)->warehouse()->id,
            'document_date' => $date, 'supplier_reference' => $reference, 'notes' => 'Operación ficticia [DEMO].',
            'created_by' => $actor->id, 'total' => '0.00',
        ])->save();
        $purchase->refresh();
        foreach ($products as $index => $product) {
            $quantity = app(DecimalService::class)->quantity($quantities[$index], (int) $product->unit->decimal_places, true);
            $purchase->items()->create([
                'product_id' => $product->id, 'unit_id' => $product->unit_id, 'product_sku' => $product->sku, 'product_name' => $product->name,
                'quantity' => $quantity, 'unit_cost' => $product->reference_cost,
                'line_total' => app(DecimalService::class)->lineTotal($quantity, $product->reference_cost),
            ]);
        }
        app(AuditService::class)->record($actor, 'purchases.created', $purchase, [], $purchase->getAttributes());

        return $purchase;
    }

    private function confirmPurchase(User $actor, Purchase $purchase, string $key): Purchase
    {
        $lines = $purchase->items->map(fn ($item) => ['product_id' => $item->product_id, 'quantity' => (string) $item->quantity, 'unit_cost' => (string) $item->unit_cost])->all();

        return app(PurchaseService::class)->confirmPurchase($purchase, $lines, $actor, $key);
    }
}
