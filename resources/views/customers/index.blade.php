@extends('layouts.app')
@section('title', 'Clientes')
@section('content')
<div class="page-heading">
    <div>
        <p class="eyebrow">VENTAS</p>
        <h1>Clientes</h1>
        <p class="text-secondary">Administre los clientes conservando su historial.</p>
    </div>
    @can('customers.create')
        <a class="btn btn-primary" href="{{ route('customers.create') }}">Agregar cliente</a>
    @endcan
</div>

<form method="GET" class="filter-panel row g-3 mb-4">
    <div class="col-md-6">
        <label class="form-label" for="q">Nombre, código, NIT, teléfono o correo</label>
        <input class="form-control" id="q" name="q" value="{{ request('q') }}">
    </div>
    <div class="col-md-3">
        <label class="form-label" for="active">Estado</label>
        <select class="form-select" id="active" name="active">
            <option value="">Todos</option>
            <option value="1" @selected(request('active') === '1')>Activos</option>
            <option value="0" @selected(request('active') === '0')>Inactivos</option>
        </select>
    </div>
    <div class="col-md-3 d-flex align-items-end gap-2">
        <button class="btn btn-primary" type="submit">Buscar</button>
        <a class="btn btn-light" href="{{ route('customers.index') }}">Limpiar</a>
    </div>
</form>

<div class="surface table-responsive">
    <table class="table align-middle mb-0">
        <thead>
            <tr>
                <th>Código</th><th>Cliente</th><th>NIT</th>
                <th>Teléfono</th><th>Correo</th><th>Estado</th><th></th>
            </tr>
        </thead>
        <tbody>
            @forelse($customers as $customer)
                <tr>
                    <td>{{ $customer->code }}</td>
                    <td><a class="record-link" href="{{ route('customers.show', $customer) }}">{{ $customer->name }}</a></td>
                    <td>{{ $customer->tax_id ?: '—' }}</td>
                    <td>{{ $customer->phone ?: '—' }}</td>
                    <td>{{ $customer->email ?: '—' }}</td>
                    <td><x-status :active="$customer->active" /></td>
                    <td><a class="btn btn-sm btn-outline-primary" href="{{ route('customers.show', $customer) }}">Ver</a></td>
                </tr>
            @empty
                <tr><td colspan="7" class="empty-state">No hay clientes con esos filtros.</td></tr>
            @endforelse
        </tbody>
    </table>
    <div class="p-3">{{ $customers->links() }}</div>
</div>
@endsection