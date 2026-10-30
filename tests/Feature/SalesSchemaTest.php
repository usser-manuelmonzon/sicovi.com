<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\DatabaseTestCase;

class SalesSchemaTest extends DatabaseTestCase
{
    public function test_postgres_rejects_draft_to_voided_transition_and_has_partial_sale_origin_index(): void
    {
        $actor = new User();
        $actor->forceFill([
            'name' => 'Usuario de esquema ventas',
            'email' => 'sales-schema@example.test',
            'password' => 'Fixture-only-passphrase-2026!',
            'role_id' => Role::where('code', 'admin')->sole()->id,
            'active' => true,
        ])->save();
        $warehouseId = (int) DB::table('warehouses')->value('id');
        $saleId = DB::table('sales')->insertGetId([
            'warehouse_id' => $warehouseId,
            'document_date' => '2026-10-29',
            'created_by' => $actor->id,
        ]);

        try {
            DB::transaction(fn () => DB::table('sales')->where('id', $saleId)->update([
                'state' => 'voided',
                'confirmed_by' => $actor->id,
                'confirmed_at' => now(),
                'confirmation_idempotency_key' => 'direct-void',
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'void_reason' => 'Intento directo',
            ]));
            $this->fail('PostgreSQL debe rechazar borrador → anulada.');
        } catch (QueryException $exception) {
            $this->assertSame('23514', $exception->getCode());
        }

        $index = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE schemaname = 'sicovi' AND indexname = 'inventory_movements_sale_item_unique'");
        $this->assertNotNull($index);
        $this->assertStringContainsString('WHERE', strtoupper($index->indexdef));
    }
}
