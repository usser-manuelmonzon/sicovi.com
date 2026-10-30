<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\CustomerService;
use App\Services\InventoryService;
use App\Services\SaleService;
use Illuminate\Validation\ValidationException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

class SaleCustomerServiceTest extends DatabaseTestCase
{
    private User $admin;
    private int $warehouseId;
    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = new User();
        $this->admin->forceFill([
            'name' => 'Administrador de ventas',
            'email' => 'sales-service@example.test',
            'password' => 'Fixture-only-passphrase-2026!',
            'role_id' => Role::where('code', 'admin')->sole()->id,
            'active' => true,
        ])->save();
        $this->warehouseId = (int) DB::table('warehouses')->value('id');
        $unitId = (int) DB::table('units')->where('code', 'UND')->value('id');
        $categoryId = DB::table('categories')->insertGetId([
            'code' => 'T-SALE-SVC', 'name' => 'Categoría ventas servicio',
        ]);
        $this->productId = DB::table('products')->insertGetId([
            'sku' => 'T-SALE-SVC-1', 'name' => 'Producto ventas',
            'category_id' => $categoryId, 'unit_id' => $unitId,
            'sale_price' => '12.50', 'reference_cost' => '5.00',
            'minimum_stock' => '0.000', 'active' => true,
        ]);
        DB::table('inventory_balances')->insert([
            'product_id' => $this->productId,
            'warehouse_id' => $this->warehouseId,
            'quantity' => '0.000',
        ]);
    }

    public function test_customer_lifecycle_duplicate_tax_id_and_audit(): void
    {
        $service = app(CustomerService::class);
        $customer = $service->create($this->admin, [
            'name' => '  Cliente Uno ', 'tax_id' => '123-456',
        ]);
        $service->update($customer, $this->admin, [
            'name' => 'Cliente Actualizado', 'tax_id' => '123456',
        ]);
        $this->assertSame(1, $service->search('Actualizado')->total());
        $service->deactivate($customer, $this->admin);
        $this->assertFalse($customer->fresh()->active);
        $this->assertSame(3, DB::table('audit_events')
            ->where('entity_type', Customer::class)
            ->where('entity_id', $customer->id)->count());

        $this->expectException(ValidationException::class);
        $service->create($this->admin, [
            'name' => 'Cliente duplicado', 'tax_id' => '12 34 56',
        ]);
    }

    public function test_sale_confirmation_uses_saved_draft_quantity_and_is_idempotent(): void
    {
        $this->addOpeningStock('5');
        $customer = app(CustomerService::class)->create($this->admin, [
            'name' => 'Cliente de venta', 'tax_id' => '555-123',
        ]);
        $service = app(SaleService::class);
        $sale = $service->createDraft($this->admin, [
            'customer_id' => $customer->id,
            'document_date' => '2026-10-29',
        ], [[ 'product_id' => $this->productId, 'quantity' => '2' ]]);
        DB::table('products')->where('id', $this->productId)->update(['sale_price' => '15.00']);

        $confirmed = $service->confirmSale($sale, $this->admin, 'confirm-1');
        $replay = $service->confirmSale($sale, $this->admin, 'confirm-1');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame('confirmed', $confirmed->state);
        $this->assertSame('15.00', $confirmed->items->first()->unit_price);
        $this->assertSame($confirmed->id, $replay->id);
        $this->assertSame('3.000', DB::table('inventory_balances')
            ->where('product_id', $this->productId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_movements')
            ->where('sale_item_id', $sale->items->first()->id)
            ->whereNull('reversal_of_id')->count());
        $this->assertSame(1, DB::table('idempotency_records')
            ->where('action', 'sales.confirm')->count());

        $other = $service->createDraft($this->admin, [
            'customer_id' => $customer->id, 'document_date' => '2026-10-29',
        ], [[ 'product_id' => $this->productId, 'quantity' => '1' ]]);
        try {
            $service->confirmSale($other, $this->admin, 'confirm-1');
            $this->fail('La clave de confirmación no debe reutilizarse en otra venta.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }
        try {
            $service->updateDraft($sale, $this->admin, [], [
                ['product_id' => $this->productId, 'quantity' => '4'],
            ]);
            $this->fail('No se debe editar una venta confirmada.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('sale', $exception->errors());
        }
    }

    public function test_editing_a_draft_audits_old_and_new_product_quantities(): void
    {
        $service = app(SaleService::class);
        $sale = $service->createDraft($this->admin, [
            'document_date' => '2026-10-29',
        ], [[ 'product_id' => $this->productId, 'quantity' => '2' ]]);
        $service->updateDraft($sale, $this->admin, [
            'document_date' => '2026-10-29',
        ], [[ 'product_id' => $this->productId, 'quantity' => '3' ]]);

        $event = DB::table('audit_events')
            ->where('action', 'sales.updated')
            ->where('entity_id', $sale->id)->sole();
        $before = json_decode($event->before_values, true, flags: JSON_THROW_ON_ERROR);
        $after = json_decode($event->after_values, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('2.000', json_decode($before['lines'], true)[0]['quantity']);
        $this->assertSame('3.000', json_decode($after['lines'], true)[0]['quantity']);
    }

    public function test_central_inventory_service_can_reverse_a_voided_sales_output_once(): void
    {
        $this->addOpeningStock('5');
        $sale = app(SaleService::class)->createDraft($this->admin, [
            'document_date' => '2026-10-29',
        ], [[ 'product_id' => $this->productId, 'quantity' => '2' ]]);
        $sale = app(SaleService::class)->confirmSale($sale, $this->admin, 'reverse-sale-1');
        $saleItemId = (int) $sale->items->first()->id;

        DB::transaction(function () use ($sale, $saleItemId): void {
            DB::table('sales')->where('id', $sale->id)->update([
                'state' => 'voided', 'voided_by' => $this->admin->id,
                'voided_at' => now(), 'void_reason' => 'Prueba de anulación',
            ]);
            app(InventoryService::class)->reverseSaleLines(
                $this->admin, $this->warehouseId, [$saleItemId], 'Prueba de anulación',
            );
        });
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame('5.000', DB::table('inventory_balances')
            ->where('product_id', $this->productId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_movements')
            ->where('sale_item_id', $saleItemId)
            ->whereNotNull('reversal_of_id')->count());
        try {
            DB::transaction(fn () => app(InventoryService::class)->reverseSaleLines(
                $this->admin, $this->warehouseId, [$saleItemId], 'Otra vez',
            ));
            $this->fail('La salida solo puede revertirse una vez.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }
    }

    public function test_sale_void_reverses_once_audits_and_replays_idempotently(): void
    {
        $this->addOpeningStock('2');
        $service = app(SaleService::class);
        $sale = $service->createDraft($this->admin, ['document_date' => '2026-10-29'], [
            ['product_id' => $this->productId, 'quantity' => '2'],
        ]);
        $sale = $service->confirmSale($sale, $this->admin, 'void-confirm-1');

        $voided = $service->voidSale($sale, $this->admin, 'Error de captura', 'void-key-1');
        $replay = $service->voidSale($sale, $this->admin, 'Error de captura', 'void-key-1');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame('voided', $voided->state);
        $this->assertSame($voided->id, $replay->id);
        $this->assertSame('2.000', DB::table('inventory_balances')->where('product_id', $this->productId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_movements')->where('sale_item_id', $sale->items->first()->id)->whereNotNull('reversal_of_id')->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sales.voided')->where('entity_id', $sale->id)->count());
        $this->assertSame(1, DB::table('idempotency_records')->where('action', 'sales.void')->count());

        $this->expectException(ValidationException::class);
        $service->voidSale($sale, $this->admin, 'Motivo distinto', 'void-key-1');
    }

    public function test_draft_cancellation_is_audited_and_does_not_change_inventory(): void
    {
        $sale = app(SaleService::class)->createDraft($this->admin, ['document_date' => '2026-10-29'], [
            ['product_id' => $this->productId, 'quantity' => '3'],
        ]);
        $cancelled = app(SaleService::class)->cancelDraft($sale, $this->admin, 'Cliente desistió');
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

        $this->assertSame('cancelled', $cancelled->state);
        $this->assertSame('0.000', DB::table('inventory_balances')->where('product_id', $this->productId)->value('quantity'));
        $this->assertSame(0, DB::table('inventory_movements')->where('sale_item_id', $sale->items->first()->id)->count());
        $this->assertSame(1, DB::table('audit_events')->where('action', 'sales.cancelled')->where('entity_id', $sale->id)->count());
    }

    public function test_inactive_customer_and_insufficient_stock_are_rejected(): void
    {
        $customerService = app(CustomerService::class);
        $customer = $customerService->create($this->admin, [
            'name' => 'Cliente inactivo', 'tax_id' => '555-456',
        ]);
        $customerService->deactivate($customer, $this->admin);

        try {
            app(SaleService::class)->createDraft($this->admin, [
                'customer_id' => $customer->id, 'document_date' => '2026-10-29',
            ], [[ 'product_id' => $this->productId, 'quantity' => '1' ]]);
            $this->fail('No se debe crear una venta con cliente inactivo.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_id', $exception->errors());
        }

        $sale = app(SaleService::class)->createDraft($this->admin, [
            'document_date' => '2026-10-29',
        ], [[ 'product_id' => $this->productId, 'quantity' => '1' ]]);
        try {
            app(SaleService::class)->confirmSale($sale, $this->admin, 'no-stock');
            $this->fail('La venta sin existencias debe rechazarse.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('lines', $exception->errors());
        }
        $this->assertSame('draft', $sale->fresh()->state);
        $this->assertSame(0, DB::table('inventory_movements')->count());
    }

    public function test_real_http_routes_confirm_the_saved_lines_and_enforce_permissions_and_filters(): void
    {
        $this->addOpeningStock('4');
        $this->actingAs($this->admin)
            ->post('/customers', ['name' => 'Cliente HTTP', 'tax_id' => '998-1'])
            ->assertRedirect();
        $customerId = (int) DB::table('customers')->where('tax_id', '998-1')->value('id');

        $this->actingAs($this->admin)
            ->post('/sales', [
                'customer_id' => $customerId,
                'document_date' => '2026-10-29',
                'lines' => [[ 'product_id' => $this->productId, 'quantity' => '1' ]],
            ])->assertRedirect();
        $sale = Sale::query()->latest('id')->firstOrFail();

        $this->post('/sales/'.$sale->id.'/confirm', [
            'idempotency_key' => 'http-confirm-1',
            'lines' => [[ 'product_id' => $this->productId, 'quantity' => '99' ]],
        ])->assertRedirect();
        DB::statement('SET CONSTRAINTS ALL IMMEDIATE');
        $this->assertSame('confirmed', $sale->fresh()->state);
        $this->assertSame('-1.000', DB::table('inventory_movements')
            ->where('sale_item_id', $sale->items()->value('id'))
            ->whereNull('reversal_of_id')->value('quantity'));

        $this->get('/customers?active=invalid')->assertSessionHasErrors('active');
        $this->actingAs($this->admin)->get('/sales?from=no-es-fecha')->assertSessionHasErrors('from');
        $this->get('/sales?to=2026-01-01&from=2026-02-01')->assertSessionHasErrors('to');

        $viewer = new User();
        $viewer->forceFill([
            'name' => 'Consulta sin ventas', 'email' => 'sales-viewer@example.test',
            'password' => 'Fixture-only-passphrase-2026!',
            'role_id' => Role::where('code', 'viewer')->sole()->id, 'active' => true,
        ])->save();
        $this->flushSession()->actingAs($viewer)->get('/customers')->assertForbidden();
        $this->get('/sales')->assertForbidden();
        $this->post('/sales/'.$sale->id.'/void', [
            'reason' => 'Sin permiso', 'idempotency_key' => 'viewer-void-attempt',
        ])->assertForbidden();
    }

    public function test_real_http_routes_void_and_cancel_sales_with_validation_idempotency_and_authorization(): void
    {
        $this->addOpeningStock('8');
        $service = app(SaleService::class);
        $confirmed = $service->createDraft($this->admin, ['document_date' => '2026-10-29'], [
            ['product_id' => $this->productId, 'quantity' => '2'],
        ]);
        $service->confirmSale($confirmed, $this->admin, 'http-void-confirm');
        $voidPayload = ['reason' => 'Documento duplicado', 'idempotency_key' => 'http-void-key'];

        $this->actingAs($this->admin)->post('/sales/'.$confirmed->id.'/void', $voidPayload)
            ->assertRedirect('/sales/'.$confirmed->id);
        $this->post('/sales/'.$confirmed->id.'/void', $voidPayload)
            ->assertRedirect('/sales/'.$confirmed->id);
        $this->from('/sales/'.$confirmed->id)->post('/sales/'.$confirmed->id.'/void', [
            'reason' => 'Otro intento', 'idempotency_key' => 'http-void-key-2',
        ])->assertSessionHasErrors('sale');
        $this->assertSame('voided', $confirmed->fresh()->state);
        $this->assertSame('8.000', (string) DB::table('inventory_balances')->where('product_id', $this->productId)->value('quantity'));
        $this->assertSame(1, DB::table('inventory_movements')->where('sale_item_id', $confirmed->items()->value('id'))->whereNotNull('reversal_of_id')->count());
        $this->get('/sales/'.$confirmed->id)->assertOk()->assertSee('Anulación de venta confirmada')->assertSee('Documento duplicado')->assertSee($this->admin->name);

        $draft = $service->createDraft($this->admin, ['document_date' => '2026-10-29'], [
            ['product_id' => $this->productId, 'quantity' => '1'],
        ]);
        $this->from('/sales/'.$draft->id)->post('/sales/'.$draft->id.'/void', [
            'reason' => 'No debe anular un borrador', 'idempotency_key' => 'draft-void-key',
        ])
            ->assertSessionHasErrors('sale');
        $this->from('/sales/'.$draft->id)->post('/sales/'.$draft->id.'/void', ['idempotency_key' => 'http-missing-reason'])
            ->assertSessionHasErrors('reason');
        $this->post('/sales/'.$draft->id.'/cancel', ['reason' => 'El cliente desistió'])
            ->assertRedirect('/sales/'.$draft->id);
        $this->assertSame('cancelled', $draft->fresh()->state);
        $this->assertSame(0, DB::table('inventory_movements')->where('sale_item_id', $draft->items()->value('id'))->count());
        $this->get('/sales/'.$draft->id)->assertOk()->assertSee('Cancelación de borrador')->assertSee('El cliente desistió');
        $this->post('/sales/'.$draft->id.'/confirm', ['idempotency_key' => 'confirm-cancelled-draft'])
            ->assertSessionHasErrors('sale');
        $this->from('/sales/'.$draft->id)->post('/sales/'.$draft->id.'/cancel', [])
            ->assertSessionHasErrors('reason');

        $viewer = new User();
        $viewer->forceFill([
            'name' => 'Visor de ventas', 'email' => 'http-void-viewer@example.test',
            'password' => 'Fixture-only-passphrase-2026!',
            'role_id' => Role::where('code', 'viewer')->sole()->id, 'active' => true,
        ])->save();
        $this->flushSession()->actingAs($viewer)
            ->post('/sales/'.$confirmed->id.'/void', $voidPayload)->assertForbidden();
        $this->flushSession()->actingAs($viewer)
            ->post('/sales/'.$draft->id.'/cancel', ['reason' => 'Sin permiso'])->assertForbidden();
    }

    public function test_sale_void_http_failure_rolls_back_state_movements_and_audit(): void
    {
        $this->addOpeningStock('3');
        $sale = app(SaleService::class)->createDraft($this->admin, ['document_date' => '2026-10-29'], [
            ['product_id' => $this->productId, 'quantity' => '1'],
        ]);
        app(SaleService::class)->confirmSale($sale, $this->admin, 'rollback-void-confirm');
        $originalCount = DB::table('inventory_movements')->count();
        $balance = (string) DB::table('inventory_balances')->where('product_id', $this->productId)->value('quantity');
        $this->mock(InventoryService::class)->shouldReceive('reverseSaleLines')->once()->andThrow(new \RuntimeException('Fallo simulado al revertir inventario.'));

        $this->withoutExceptionHandling();
        try {
            $this->actingAs($this->admin)->post('/sales/'.$sale->id.'/void', [
                'reason' => 'Prueba de rollback', 'idempotency_key' => 'rollback-void-key',
            ]);
            $this->fail('El error de reversión debe propagarse a la prueba para comprobar rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Fallo simulado al revertir inventario.', $exception->getMessage());
        }

        $this->assertSame('confirmed', $sale->fresh()->state);
        $this->assertSame($balance, (string) DB::table('inventory_balances')->where('product_id', $this->productId)->value('quantity'));
        $this->assertSame($originalCount, DB::table('inventory_movements')->count());
        $this->assertSame(0, DB::table('audit_events')->where('action', 'sales.voided')->where('entity_id', $sale->id)->count());
        $this->assertSame(0, DB::table('idempotency_records')->where('action', 'sales.void')->count());
    }

    private function addOpeningStock(string $quantity): void
    {
        app(InventoryService::class)->applyAdjustment($this->admin, [
            'type' => 'opening', 'adjustment_date' => '2026-10-29',
            'reason' => 'Existencia inicial para prueba',
            'lines' => [[ 'product_id' => $this->productId, 'quantity' => $quantity ]],
        ]);
    }
}
