@extends('layouts.app')

@section('title', 'Reportes')

@section('content')
<div class="page-heading">
    <div>
        <p class="eyebrow">CONSULTAS ADMINISTRATIVAS</p>
        <h1>Reportes</h1>
        <p class="text-secondary">Consultas de solo lectura. Los resultados se muestran paginados y no incluyen exportación CSV.</p>
    </div>
</div>

<form method="GET" action="{{ route('reports.index') }}" class="filter-panel row g-3 mb-4">
    <div class="col-md-3">
        <label class="form-label" for="report">Reporte</label>
        <select class="form-select" id="report" name="report">
            @foreach(['sales' => 'Ventas', 'purchases' => 'Compras', 'inventory' => 'Existencias', 'kardex' => 'Kardex', 'cancellations' => 'Anulaciones'] as $value => $label)
                <option value="{{ $value }}" @selected($report === $value)>{{ $label }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="q">Buscar</label>
        <input class="form-control" id="q" name="q" maxlength="120" value="{{ $filters['q'] ?? '' }}" placeholder="Documento, SKU o nombre">
    </div>
    @if(in_array($report, ['sales', 'purchases'], true))
        <div class="col-md-2">
            <label class="form-label" for="state">Estado</label>
            <select class="form-select" id="state" name="state">
                <option value="">Todos</option>
                @foreach(($report === 'sales' ? ['draft' => 'Borrador', 'confirmed' => 'Confirmada', 'voided' => 'Anulada', 'cancelled' => 'Cancelada'] : ['draft' => 'Borrador', 'confirmed' => 'Confirmada', 'voided' => 'Anulada']) as $value => $label)
                    <option value="{{ $value }}" @selected(($filters['state'] ?? '') === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
    @endif
    @if($report === 'sales')
        <div class="col-md-2"><label class="form-label" for="customer_id">Cliente</label><select class="form-select" id="customer_id" name="customer_id"><option value="">Todos</option>@foreach($customers as $customer)<option value="{{ $customer->id }}" @selected(($filters['customer_id'] ?? '') == $customer->id)>{{ $customer->name }}</option>@endforeach</select></div>
        <div class="col-md-2"><label class="form-label" for="user_id">Creador</label><select class="form-select" id="user_id" name="user_id"><option value="">Todos</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected(($filters['user_id'] ?? '') == $user->id)>{{ $user->name }}</option>@endforeach</select></div>
    @elseif($report === 'purchases')
        <div class="col-md-3"><label class="form-label" for="supplier_id">Proveedor</label><select class="form-select" id="supplier_id" name="supplier_id"><option value="">Todos</option>@foreach($suppliers as $supplier)<option value="{{ $supplier->id }}" @selected(($filters['supplier_id'] ?? '') == $supplier->id)>{{ $supplier->name }}</option>@endforeach</select></div>
        <div class="col-md-3"><label class="form-label" for="warehouse_id">Bodega</label><select class="form-select" id="warehouse_id" name="warehouse_id"><option value="">Todas</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
    @elseif(in_array($report, ['inventory', 'kardex'], true))
        <div class="col-md-3"><label class="form-label" for="warehouse_id">Bodega</label><select class="form-select" id="warehouse_id" name="warehouse_id"><option value="">Todas</option>@foreach($warehouses as $warehouse)<option value="{{ $warehouse->id }}" @selected(($filters['warehouse_id'] ?? '') == $warehouse->id)>{{ $warehouse->name }}</option>@endforeach</select></div>
        @if($report === 'inventory')<div class="col-md-3"><label class="form-label" for="category_id">Categoría</label><select class="form-select" id="category_id" name="category_id"><option value="">Todas</option>@foreach($categories as $category)<option value="{{ $category->id }}" @selected(($filters['category_id'] ?? '') == $category->id)>{{ $category->name }}</option>@endforeach</select></div>@else<div class="col-md-3"><label class="form-label" for="movement_type">Movimiento</label><select class="form-select" id="movement_type" name="movement_type"><option value="">Todos</option>@foreach(['purchase','sale','opening','extraordinary_entry','count_adjustment','reversal'] as $movement)<option value="{{ $movement }}" @selected(($filters['movement_type'] ?? '') === $movement)>{{ $movement }}</option>@endforeach</select></div><div class="col-md-3"><label class="form-label" for="origin">Origen</label><select class="form-select" id="origin" name="origin"><option value="">Todos</option><option value="sales" @selected(($filters['origin'] ?? '') === 'sales')>Ventas</option><option value="purchases" @selected(($filters['origin'] ?? '') === 'purchases')>Compras</option><option value="adjustments" @selected(($filters['origin'] ?? '') === 'adjustments')>Ajustes</option></select></div>@endif
    @elseif($report === 'cancellations')
        <div class="col-md-3"><label class="form-label" for="origin">Origen</label><select class="form-select" id="origin" name="origin"><option value="">Todos</option><option value="sales" @selected(($filters['origin'] ?? '') === 'sales')>Ventas</option><option value="purchases" @selected(($filters['origin'] ?? '') === 'purchases')>Compras</option><option value="adjustments" @selected(($filters['origin'] ?? '') === 'adjustments')>Ajustes</option></select></div>
        <div class="col-md-3"><label class="form-label" for="user_id">Usuario</label><select class="form-select" id="user_id" name="user_id"><option value="">Todos</option>@foreach($users as $user)<option value="{{ $user->id }}" @selected(($filters['user_id'] ?? '') == $user->id)>{{ $user->name }}</option>@endforeach</select></div>
    @endif
    @if(in_array($report, ['sales', 'purchases', 'kardex', 'cancellations'], true))
        <div class="col-md-2"><label class="form-label" for="from">Desde</label><input type="date" class="form-control" id="from" name="from" value="{{ $filters['from'] ?? '' }}"></div>
        <div class="col-md-2"><label class="form-label" for="to">Hasta</label><input type="date" class="form-control" id="to" name="to" value="{{ $filters['to'] ?? '' }}"></div>
    @endif
    <div class="col-12 d-flex gap-2"><button class="btn btn-primary" type="submit">Aplicar filtros</button><a class="btn btn-light" href="{{ route('reports.index', ['report' => $report]) }}">Limpiar</a></div>
</form>

<div class="surface table-responsive">
    <table class="table align-middle mb-0">
        <thead><tr>@foreach($columns as $label)<th>{{ $label }}</th>@endforeach</tr></thead>
        <tbody>
            @forelse($rows as $row)
                <tr>@foreach($columns as $field => $label)<td>
                    @php($value = data_get($row, $field))
                    @if($field === 'source_code' && $value)
                        @php($sourceRoute = match(data_get($row, 'source_type')) { 'sales' => 'sales.show', 'purchases' => 'purchases.show', 'adjustments' => 'inventory.adjustments.show', default => null })
                        @php($sourcePermission = match(data_get($row, 'source_type')) { 'sales' => 'sales.view', 'purchases' => 'purchases.view', 'adjustments' => 'inventory.view', default => null })
                        @if($sourceRoute && $sourcePermission && auth()->user()->hasPermission($sourcePermission))<a href="{{ route($sourceRoute, data_get($row, 'source_id')) }}">{{ $value }}</a>@else{{ $value }}@endif
                    @elseif($field === 'total' && $value !== null)
                        Q {{ number_format((float) $value, 2) }}
                    @else
                        {{ $value ?? '—' }}
                    @endif
                </td>@endforeach</tr>
            @empty<tr><td colspan="{{ count($columns) }}" class="empty-state">No hay datos para esos filtros.</td></tr>@endforelse
        </tbody>
    </table>
    <div class="p-3">{{ $rows->links() }}</div>
</div>
@endsection
