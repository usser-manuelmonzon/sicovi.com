<?php

namespace App\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class ReportController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('reports.view');
        $filters = $request->validate([
            'report' => ['nullable', 'in:sales,purchases,inventory,kardex,cancellations'],
            'q' => ['nullable', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:20'],
            'customer_id' => ['nullable', 'integer', 'min:1', 'exists:customers,id'],
            'supplier_id' => ['nullable', 'integer', 'min:1', 'exists:suppliers,id'],
            'user_id' => ['nullable', 'integer', 'min:1', 'exists:users,id'],
            'warehouse_id' => ['nullable', 'integer', 'min:1', 'exists:warehouses,id'],
            'category_id' => ['nullable', 'integer', 'min:1', 'exists:categories,id'],
            'movement_type' => ['nullable', 'in:purchase,sale,opening,extraordinary_entry,count_adjustment,reversal'],
            'origin' => ['nullable', 'in:sales,purchases,adjustments'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);
        $type = $filters['report'] ?? 'sales';
        $query = match ($type) {
            'purchases' => $this->purchases($filters),
            'inventory' => $this->inventory($filters),
            'kardex' => $this->kardex($filters),
            'cancellations' => $this->cancellations($filters),
            default => $this->sales($filters),
        };
        $columns = match ($type) {
            'purchases' => ['code' => 'Compra', 'document_date' => 'Fecha', 'supplier' => 'Proveedor', 'supplier_reference' => 'Referencia', 'warehouse' => 'Bodega', 'state' => 'Estado', 'total' => 'Total'],
            'inventory' => ['sku' => 'SKU', 'product' => 'Producto', 'category' => 'Categoría', 'warehouse' => 'Bodega', 'quantity' => 'Existencia', 'minimum_stock' => 'Mínimo'],
            'kardex' => ['occurred_at' => 'Fecha/hora', 'sku' => 'SKU', 'product' => 'Producto', 'warehouse' => 'Bodega', 'movement_type' => 'Movimiento', 'quantity' => 'Cantidad', 'balance_before' => 'Antes', 'balance_after' => 'Después', 'actor' => 'Usuario'],
            'cancellations' => ['kind' => 'Tipo', 'code' => 'Documento', 'document_date' => 'Fecha documento', 'changed_at' => 'Fecha anulación', 'party' => 'Cliente / proveedor', 'reason' => 'Motivo', 'actor' => 'Usuario', 'total' => 'Total'],
            default => ['code' => 'Venta', 'document_date' => 'Fecha', 'customer' => 'Cliente', 'creator' => 'Creada por', 'state' => 'Estado', 'total' => 'Total'],
        };

        return view('reports.index', [
            'rows' => $query->paginate(25)->withQueryString(),
            'columns' => $columns,
            'filters' => $filters,
            'report' => $type,
            'warehouses' => DB::table('warehouses')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'categories' => DB::table('categories')->where('active', true)->orderBy('name')->get(['id', 'name']),
            'customers' => DB::table('customers')->orderBy('name')->get(['id', 'name']),
            'suppliers' => DB::table('suppliers')->orderBy('name')->get(['id', 'name']),
            'users' => DB::table('users')->orderBy('name')->get(['id', 'name']),
        ]);
    }

    private function sales(array $filters): mixed
    {
        return DB::table('sales as s')->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->join('users as u', 'u.id', '=', 's.created_by')
            ->select(['s.code', 's.document_date', 's.state', 's.total', 'c.name as customer', 'u.name as creator'])
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where(fn ($n) => $n->where('s.code', 'ilike', '%'.$filters['q'].'%')->orWhere('c.name', 'ilike', '%'.$filters['q'].'%')))
            ->when(isset($filters['state']), fn ($q) => $q->where('s.state', $filters['state']))
            ->when(isset($filters['customer_id']), fn ($q) => $q->where('s.customer_id', $filters['customer_id']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('s.created_by', $filters['user_id']))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('s.document_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('s.document_date', '<=', $filters['to']))
            ->orderByDesc('s.document_date')->orderByDesc('s.id');
    }

    private function purchases(array $filters): mixed
    {
        return DB::table('purchases as p')->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->join('warehouses as w', 'w.id', '=', 'p.warehouse_id')
            ->select(['p.code', 'p.document_date', 'p.state', 'p.total', 'p.supplier_reference', 's.name as supplier', 'w.name as warehouse'])
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where(fn ($n) => $n->where('p.code', 'ilike', '%'.$filters['q'].'%')->orWhere('p.supplier_reference', 'ilike', '%'.$filters['q'].'%')->orWhere('s.name', 'ilike', '%'.$filters['q'].'%')))
            ->when(isset($filters['state']), fn ($q) => $q->where('p.state', $filters['state']))
            ->when(isset($filters['supplier_id']), fn ($q) => $q->where('p.supplier_id', $filters['supplier_id']))
            ->when(isset($filters['warehouse_id']), fn ($q) => $q->where('p.warehouse_id', $filters['warehouse_id']))
            ->when(isset($filters['from']), fn ($q) => $q->whereDate('p.document_date', '>=', $filters['from']))
            ->when(isset($filters['to']), fn ($q) => $q->whereDate('p.document_date', '<=', $filters['to']))
            ->orderByDesc('p.document_date')->orderByDesc('p.id');
    }

    private function inventory(array $filters): mixed
    {
        return DB::table('inventory_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')
            ->join('warehouses as w', 'w.id', '=', 'b.warehouse_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->select(['p.sku', 'p.name as product', 'c.name as category', 'w.name as warehouse', 'b.quantity', 'p.minimum_stock'])
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where(fn ($n) => $n->where('p.sku', 'ilike', '%'.$filters['q'].'%')->orWhere('p.name', 'ilike', '%'.$filters['q'].'%')))
            ->when(isset($filters['warehouse_id']), fn ($q) => $q->where('b.warehouse_id', $filters['warehouse_id']))
            ->when(isset($filters['category_id']), fn ($q) => $q->where('p.category_id', $filters['category_id']))
            ->orderBy('p.name')->orderBy('p.id')->orderBy('w.id');
    }

    private function kardex(array $filters): mixed
    {
        return DB::table('inventory_movements as m')->join('products as p', 'p.id', '=', 'm.product_id')
            ->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')->join('users as u', 'u.id', '=', 'm.actor_id')
            ->select(['m.id', 'm.occurred_at', 'p.sku', 'p.name as product', 'w.name as warehouse', 'm.movement_type', 'm.quantity', 'm.balance_before', 'm.balance_after', 'u.name as actor'])
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where(fn ($n) => $n->where('p.sku', 'ilike', '%'.$filters['q'].'%')->orWhere('p.name', 'ilike', '%'.$filters['q'].'%')))
            ->when(isset($filters['warehouse_id']), fn ($q) => $q->where('m.warehouse_id', $filters['warehouse_id']))
            ->when(isset($filters['movement_type']), fn ($q) => $q->where('m.movement_type', $filters['movement_type']))
            ->when(isset($filters['origin']), function ($q) use ($filters): void {
                $originColumn = match ($filters['origin']) {
                    'sales' => 'm.sale_item_id',
                    'purchases' => 'm.purchase_item_id',
                    default => 'm.adjustment_item_id',
                };
                $q->whereNotNull($originColumn);
            })
            ->when(isset($filters['from']), fn ($q) => $q->where('m.occurred_at', '>=', $this->localDateStart($filters['from'])))
            ->when(isset($filters['to']), fn ($q) => $q->where('m.occurred_at', '<=', $this->localDateEnd($filters['to'])))
            ->orderByDesc('m.occurred_at')->orderByDesc('m.id');
    }

    private function cancellations(array $filters): mixed
    {
        $sales = DB::table('sales as s')->join('users as u', 'u.id', '=', 's.voided_by')->leftJoin('customers as c', 'c.id', '=', 's.customer_id')
            ->where('s.state', 'voided')->selectRaw("'Venta'::text as kind, s.code, s.document_date, s.voided_at as changed_at, s.void_reason as reason, u.name as actor, u.id as actor_id, coalesce(c.name, 'Venta sin cliente') as party, s.total, 'sales'::text as origin");
        $purchases = DB::table('purchases as p')->join('users as u', 'u.id', '=', 'p.voided_by')->join('suppliers as s', 's.id', '=', 'p.supplier_id')
            ->where('p.state', 'voided')->selectRaw("'Compra'::text as kind, p.code, p.document_date, p.voided_at as changed_at, p.void_reason as reason, u.name as actor, u.id as actor_id, s.name as party, p.total, 'purchases'::text as origin");
        $adjustments = DB::table('inventory_adjustments as a')->join('users as u', 'u.id', '=', 'a.voided_by')
            ->where('a.state', 'voided')->selectRaw("'Ajuste'::text as kind, a.code, a.adjustment_date as document_date, a.voided_at as changed_at, a.void_reason as reason, u.name as actor, u.id as actor_id, a.type as party, NULL::numeric as total, 'adjustments'::text as origin");
        $union = $sales->unionAll($purchases)->unionAll($adjustments);
        return DB::query()->fromSub($union, 'cancellations')
            ->when(($filters['q'] ?? '') !== '', fn ($q) => $q->where(fn ($n) => $n->where('code', 'ilike', '%'.$filters['q'].'%')->orWhere('reason', 'ilike', '%'.$filters['q'].'%')->orWhere('party', 'ilike', '%'.$filters['q'].'%')))
            ->when(isset($filters['origin']), fn ($q) => $q->where('origin', $filters['origin']))
            ->when(isset($filters['user_id']), fn ($q) => $q->where('actor_id', $filters['user_id']))
            ->when(isset($filters['from']), fn ($q) => $q->where('changed_at', '>=', $this->localDateStart($filters['from'])))
            ->when(isset($filters['to']), fn ($q) => $q->where('changed_at', '<=', $this->localDateEnd($filters['to'])))
            ->orderByDesc('changed_at')->orderByDesc('code');
    }

    private function localDateStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'America/Guatemala')->startOfDay()->utc();
    }

    private function localDateEnd(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'America/Guatemala')->endOfDay()->utc();
    }
}
