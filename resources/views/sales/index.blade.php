
@extends('layouts.app')

@section('title', 'Ventas')

@section('content')
<div class="page-heading">
    <div>
        <p class="eyebrow">VENTAS E INVENTARIO</p>
        <h1>Ventas</h1>
        <p class="text-secondary">Consulte borradores y ventas confirmadas.</p>
    </div>

    @can('sales.create')
        <a class="btn btn-primary" href="{{ route('sales.create') }}">
            Nueva venta
        </a>
    @endcan
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

<form method="GET" class="filter-panel row g-3 mb-4">
    <div class="col-md-4">
        <label class="form-label" for="q">Número de venta o cliente</label>
        <input
            class="form-control"
            id="q"
            name="q"
            value="{{ request('q') }}"
        >
    </div>

    <div class="col-md-2">
        <label class="form-label" for="state">Estado</label>
        <select class="form-select" id="state" name="state">
            <option value="">Todos</option>
            <option value="draft" @selected(request('state') === 'draft')>Borrador</option>
            <option value="confirmed" @selected(request('state') === 'confirmed')>Confirmada</option>
            <option value="voided" @selected(request('state') === 'voided')>Anulada</option>
            <option value="cancelled" @selected(request('state') === 'cancelled')>Borrador cancelado</option>
        </select>
    </div>

    <div class="col-md-3">
        <label class="form-label" for="customer_id">Cliente</label>
        <select class="form-select" id="customer_id" name="customer_id">
            <option value="">Todos</option>
            @foreach($customers as $customer)
                <option value="{{ $customer->id }}" @selected((string) request('customer_id') === (string) $customer->id)>{{ $customer->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-3">
        <label class="form-label" for="created_by">Creada por</label>
        <select class="form-select" id="created_by" name="created_by">
            <option value="">Todos</option>
            @foreach($creators as $creator)
                <option value="{{ $creator->id }}" @selected((string) request('created_by') === (string) $creator->id)>{{ $creator->name }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-2">
        <label class="form-label" for="from">Desde</label>
        <input class="form-control" type="date" id="from" name="from" value="{{ request('from') }}">
    </div>
    <div class="col-md-2">
        <label class="form-label" for="to">Hasta</label>
        <input class="form-control" type="date" id="to" name="to" value="{{ request('to') }}">
    </div>
    <div class="col-md-2 d-flex align-items-end gap-2">
        <button class="btn btn-primary" type="submit">Buscar</button>
        <a class="btn btn-light" href="{{ route('sales.index') }}">Limpiar</a>
    </div>
</form>

<div class="surface table-responsive">
    <table class="table align-middle mb-0">
        <thead>
            <tr>
                <th>Número</th>
                <th>Cliente</th>
                <th>Fecha</th>
                <th>Estado</th>
                <th class="text-end">Total</th>
            </tr>
        </thead>

        <tbody>
            @forelse($sales as $sale)
                <tr>
                    <td>
                        <a class="record-link" href="{{ route('sales.show', $sale) }}">
                            {{ $sale->code }}
                        </a>
                    </td>
                    <td>{{ $sale->customer?->name ?? 'Venta sin cliente' }}</td>
                    <td>{{ $sale->document_date?->format('d/m/Y') }}</td>
                    <td>
                        @switch($sale->state)
                            @case('draft')
                                <span class="badge text-bg-secondary">Borrador</span>
                                @break
                            @case('confirmed')
                                <span class="badge text-bg-success">Confirmada</span>
                                @break
                            @case('voided')
                                <span class="badge text-bg-danger">Anulada</span>
                                @break
                            @case('cancelled')
                                <span class="badge text-bg-dark">Borrador cancelado</span>
                                @break
                            @default
                                <span class="badge text-bg-secondary">{{ $sale->state }}</span>
                        @endswitch
                    </td>
                    <td class="text-end">
                        Q {{ number_format((float) $sale->total, 2) }}
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="empty-state">
                        No hay ventas con esos filtros.
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>

    <div class="p-3">{{ $sales->links() }}</div>
</div>
@endsection
