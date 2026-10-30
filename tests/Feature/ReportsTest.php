<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

class ReportsTest extends DatabaseTestCase
{
    public function test_admin_can_open_paged_read_only_reports_and_operational_profiles_cannot(): void
    {
        $admin = $this->userFor('admin', 'report-admin@example.test');
        $viewer = $this->userFor('viewer', 'report-viewer@example.test');
        $this->actingAs($admin);

        foreach (['sales', 'purchases', 'inventory', 'kardex', 'cancellations'] as $report) {
            $this->get('/reports?report='.$report)->assertOk();
        }
        $this->get('/reports?report=sales&from=bad')->assertSessionHasErrors('from');
        $this->get('/reports?report=cancellations&to=2026-01-01&from=2026-02-01')->assertSessionHasErrors('to');
        $this->flushSession()->actingAs($viewer)->get('/reports')->assertForbidden();
    }

    public function test_purchase_sale_void_and_kardex_report_data_filters_and_document_origins(): void
    {
        $admin = $this->userFor('admin', 'report-flow-admin@example.test');
        $warehouseId = (int) DB::table('warehouses')->value('id');
        $unitId = (int) DB::table('units')->where('code', 'UND')->value('id');
        $categoryId = DB::table('categories')->insertGetId(['code' => 'RPT-FLOW', 'name' => 'Categoría reporte']);
        $productId = DB::table('products')->insertGetId([
            'sku' => 'RPT-FLOW-1', 'name' => 'Producto reporte', 'category_id' => $categoryId,
            'unit_id' => $unitId, 'sale_price' => '25.00', 'reference_cost' => '12.00',
            'minimum_stock' => '0.000', 'active' => true,
        ]);
        DB::table('inventory_balances')->insert([
            'product_id' => $productId, 'warehouse_id' => $warehouseId, 'quantity' => '0.000',
        ]);
        $supplierId = DB::table('suppliers')->insertGetId(['name' => 'Proveedor reporte', 'tax_id' => 'RPT-9001']);
        $this->actingAs($admin)->post('/purchases', [
            'supplier_id' => $supplierId, 'document_date' => '2026-10-30',
            'lines' => [['product_id' => $productId, 'quantity' => '10', 'unit_cost' => '12.00']],
        ])->assertRedirect();
        $purchaseId = (int) DB::table('purchases')->latest('id')->value('id');
        $this->post('/purchases/'.$purchaseId.'/confirm', [
            'idempotency_key' => 'reports-purchase-1',
            'lines' => [['product_id' => $productId, 'quantity' => '10', 'unit_cost' => '12.00']],
        ])->assertRedirect();

        $saleService = app(SaleService::class);
        $sale = $saleService->createDraft($admin, ['document_date' => '2026-10-30'], [
            ['product_id' => $productId, 'quantity' => '4'],
        ]);
        $saleService->confirmSale($sale, $admin, 'reports-sale-1');
        $this->assertSame('6.000', (string) DB::table('inventory_balances')->where('product_id', $productId)->value('quantity'));

        $this->actingAs($admin)->get('/reports?report=inventory&q=RPT-FLOW-1&category_id='.$categoryId.'&warehouse_id='.$warehouseId)
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->total() === 1 && (string) $rows->first()->quantity === '6.000');

        $saleService->voidSale($sale, $admin, 'Prueba de auditoría de reporte', 'reports-void-1');
        $saleCode = (string) DB::table('sales')->where('id', $sale->id)->value('code');
        $purchaseCode = (string) DB::table('purchases')->where('id', $purchaseId)->value('code');
        $this->assertSame('10.000', (string) DB::table('inventory_balances')->where('product_id', $productId)->value('quantity'));

        $this->get('/reports?report=kardex&q=RPT-FLOW-1&from=2026-10-30&to=2026-10-30')
            ->assertOk()->assertSee($purchaseCode)->assertSee($saleCode)
            ->assertSee('purchase')->assertSee('sale')->assertSee('reversal')->assertSee('Prueba de auditoría de reporte');
        $this->get('/reports?report=kardex&origin=purchases&q=RPT-FLOW-1')
            ->assertOk()->assertSee($purchaseCode)->assertDontSee($saleCode);
        $this->get('/reports?report=purchases&supplier_id='.$supplierId.'&warehouse_id='.$warehouseId.'&state=confirmed&from=2026-10-30&to=2026-10-30')
            ->assertOk()->assertSee($purchaseCode);
        $this->get('/reports?report=cancellations&origin=sales&user_id='.$admin->id.'&q='.$saleCode.'&from=2026-10-30&to=2026-10-30')
            ->assertOk()->assertSee('Venta')->assertSee($saleCode)->assertSee('Prueba de auditoría de reporte');

        $cancelledDraft = $saleService->createDraft($admin, ['document_date' => '2026-10-30'], []);
        $saleService->cancelDraft($cancelledDraft, $admin, 'El cliente desistió');
        $cancelledCode = (string) DB::table('sales')->where('id', $cancelledDraft->id)->value('code');
        $this->get('/reports?report=cancellations&origin=sales&user_id='.$admin->id.'&q='.$cancelledCode.'&from=2026-10-30&to=2026-10-30')
            ->assertOk()->assertSee('Borrador de venta cancelado')->assertSee($cancelledCode)->assertSee('El cliente desistió');
        $this->get('/reports?report=cancellations&q=NO-EXISTE-EN-REPORTES')
            ->assertOk()->assertSee('No hay datos para esos filtros.');
    }

    public function test_sales_report_paginates_at_twenty_five_rows(): void
    {
        $admin = $this->userFor('admin', 'report-page-admin@example.test');
        $warehouseId = (int) DB::table('warehouses')->value('id');
        $rows = [];
        for ($index = 0; $index < 27; $index++) {
            $rows[] = [
                'warehouse_id' => $warehouseId, 'document_date' => '2026-10-30', 'state' => 'draft',
                'version' => 0, 'total' => '0.00', 'created_by' => $admin->id,
                'created_at' => now(), 'updated_at' => now(),
            ];
        }
        DB::table('sales')->insert($rows);

        $this->actingAs($admin)->get('/reports?report=sales')
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->count() === 25 && $rows->total() === 27);
        $this->get('/reports?report=sales&q=NO-COINCIDE')
            ->assertOk()->assertViewHas('rows', fn ($rows) => $rows->isEmpty() && $rows->total() === 0);
    }

    private function userFor(string $roleCode, string $email): User
    {
        $user = new User();
        $user->forceFill([
            'name' => 'Reporte '.$roleCode,
            'email' => $email,
            'password' => 'Fixture-only-passphrase-2026!',
            'role_id' => Role::where('code', $roleCode)->sole()->id,
            'active' => true,
        ])->save();

        return $user;
    }
}
