
@extends('layouts.app')

@section('title', 'Detalle de venta')

@section('content')
<div class="page-heading">
    <div>
        <p class="eyebrow">VENTAS</p>
        <h1>Venta {{ $sale->code }}</h1>
        <p class="text-secondary">
            Cliente: {{ $sale->customer?->name ?? 'Venta sin cliente' }}
        </p>
    </div>

    <div class="d-flex gap-2">
        <a class="btn btn-light" href="{{ route('sales.index') }}">Volver</a>

        @if($sale->state === 'draft')
            @can('sales.update')
                <a class="btn btn-outline-primary" href="{{ route('sales.edit', $sale) }}">
                    Editar borrador
                </a>
            @endcan
        @endif
    </div>
</div>

@if(session('success'))
    <div class="alert alert-success">{{ session('success') }}</div>
@endif

@if($errors->any())
    <div class="alert alert-danger">
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="surface p-4 mb-4">
    <div class="row g-3">
        <div class="col-md-3">
            <strong>Fecha</strong>
            <div>{{ $sale->document_date?->format('d/m/Y') }}</div>
        </div>

        <div class="col-md-3">
            <strong>Estado</strong>
            <div>
                {{ [
                    'draft' => 'Borrador',
                    'confirmed' => 'Confirmada',
                    'voided' => 'Anulada',
                ][$sale->state] ?? $sale->state }}
            </div>
        </div>

        <div class="col-md-3">
            <strong>Bodega</strong>
            <div>{{ $sale->warehouse?->name ?? 'No disponible' }}</div>
        </div>

        <div class="col-md-3">
            <strong>Creada por</strong>
            <div>{{ $sale->creator?->name ?? 'No disponible' }}</div>
        </div>
    </div>

    @if($sale->notes)
        <hr>
        <strong>Notas</strong>
        <p class="mb-0">{{ $sale->notes }}</p>
    @endif
</div>

<div class="surface table-responsive mb-4">
    <table class="table align-middle mb-0">
        <thead>
            <tr>
                <th>Producto</th>
                <th>Unidad</th>
                <th class="text-end">Cantidad</th>
                <th class="text-end">Precio unitario</th>
                <th class="text-end">Subtotal</th>
            </tr>
        </thead>

        <tbody>
            @forelse($sale->items as $item)
                <tr>
                    <td>{{ $item->product_name }}</td>
                    <td>{{ $item->unit?->abbreviation ?? '—' }}</td>
                    <td class="text-end">{{ $item->quantity }}</td>
                    <td class="text-end">Q {{ number_format((float) $item->unit_price, 2) }}</td>
                    <td class="text-end">Q {{ number_format((float) $item->line_total, 2) }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="5" class="empty-state">
                        Esta venta todavía no tiene productos.
                    </td>
                </tr>
            @endforelse
        </tbody>

        <tfoot>
            <tr>
                <th colspan="4" class="text-end">TOTAL</th>
                <th class="text-end">Q {{ number_format((float) $sale->total, 2) }}</th>
            </tr>
        </tfoot>
    </table>
</div>

@if($sale->state === 'draft' && $sale->items->isNotEmpty())
    @can('sales.confirm')
        <div class="surface p-4">
            <h2 class="h5">Confirmar venta</h2>
            <p class="text-secondary">
                Al confirmar, el sistema validará las cantidades y descontará
                el inventario dentro de una transacción.
            </p>

            <form
                action="{{ route('sales.confirm', $sale) }}"
                method="POST"
                onsubmit="const b=this.querySelector('button[type=submit]'); if(b){b.disabled=true;b.textContent='Procesando...';}"
            >
                @csrf

                <input
                    type="hidden"
                    name="idempotency_key"
                    value="{{ (string) \Illuminate\Support\Str::uuid() }}"
                >

                @foreach($sale->items as $index => $item)
                    <input
                        type="hidden"
                        name="lines[{{ $index }}][product_id]"
                        value="{{ $item->product_id }}"
                    >
                    <input
                        type="hidden"
                        name="lines[{{ $index }}][quantity]"
                        value="{{ $item->quantity }}"
                    >
                @endforeach

                <button type="submit" class="btn btn-primary">
                    Confirmar venta
                </button>
            </form>
        </div>
    @endcan
@endif
@endsection
