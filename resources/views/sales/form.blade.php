
@extends('layouts.app')

@section('title', $sale->exists ? 'Editar venta' : 'Nueva venta')

@section('content')
<div class="page-heading">
    <div>
        <p class="eyebrow">VENTAS</p>
        <h1>{{ $sale->exists ? 'Editar borrador' : 'Nueva venta' }}</h1>
        <p class="text-secondary">
            Agregue productos y cantidades. Los precios se calculan en el servidor.
        </p>
    </div>

    <a class="btn btn-light" href="{{ $sale->exists ? route('sales.show', $sale) : route('sales.index') }}">
        Cancelar
    </a>
</div>

@if($errors->any())
    <div class="alert alert-danger">
        <strong>Revisa los siguientes errores:</strong>
        <ul class="mb-0">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<form
    method="POST"
    action="{{ $sale->exists ? route('sales.update', $sale) : route('sales.store') }}"
>
    @csrf

    @if($sale->exists)
        @method('PUT')
    @endif

    <div class="surface p-4 mb-4">
        <h2 class="h5 mb-3">Datos de la venta</h2>

        <div class="row g-3">
            <div class="col-md-6">
                <label class="form-label" for="customer_id">Cliente</label>
                <select class="form-select" id="customer_id" name="customer_id">
                    <option value="">Venta sin cliente</option>

                    @foreach($customers as $customer)
                        <option
                            value="{{ $customer->id }}"
                            @selected((string) old('customer_id', $sale->customer_id) === (string) $customer->id)
                        >
                            {{ $customer->name }}
                            {{ $customer->tax_id ? ' — NIT: '.$customer->tax_id : '' }}
                        </option>
                    @endforeach
                </select>

                <small class="text-secondary">
                    Solo aparecen clientes activos.
                </small>
            </div>

            <div class="col-md-6">
                <label class="form-label" for="document_date">Fecha</label>
                <input
                    class="form-control"
                    type="date"
                    id="document_date"
                    name="document_date"
                    value="{{ old('document_date', $sale->document_date?->format('Y-m-d') ?? now()->toDateString()) }}"
                    required
                >
            </div>

            <div class="col-12">
                <label class="form-label" for="notes">Notas</label>
                <textarea
                    class="form-control"
                    id="notes"
                    name="notes"
                    rows="3"
                    maxlength="5000"
                >{{ old('notes', $sale->notes) }}</textarea>
            </div>
        </div>
    </div>

    <div class="surface p-4 mb-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h2 class="h5 mb-0">Productos</h2>
            <button class="btn btn-outline-primary btn-sm" type="button" id="add-line">
                Agregar producto
            </button>
        </div>

        <p class="text-secondary">
            Selecciona cada producto una sola vez. La cantidad debe respetar
            los decimales permitidos por su unidad.
        </p>

        <div class="table-responsive">
            <table class="table align-middle">
                <thead>
                    <tr>
                        <th style="min-width: 250px;">Producto</th>
                        <th style="min-width: 140px;">Cantidad</th>
                        <th>Unidad</th>
                        <th></th>
                    </tr>
                </thead>

                <tbody id="sale-lines">
                    @php
                        $oldLines = old('lines');

                        if ($oldLines === null && $sale->exists) {
                            $oldLines = $sale->items->map(fn ($item) => [
                                'product_id' => $item->product_id,
                                'quantity' => $item->quantity,
                            ])->values()->all();
                        }

                        $oldLines = $oldLines ?? [];
                    @endphp

                    @foreach($oldLines as $index => $line)
                        <tr class="sale-line">
                            <td>
                                <select
                                    class="form-select product-select"
                                    name="lines[{{ $index }}][product_id]"
                                    required
                                >
                                    <option value="">Selecciona un producto</option>

                                    @foreach($products as $product)
                                        <option
                                            value="{{ $product->id }}"
                                            data-unit="{{ $product->unit?->abbreviation ?? '' }}"
                                            data-places="{{ $product->unit?->decimal_places ?? 0 }}"
                                            @selected((string) ($line['product_id'] ?? '') === (string) $product->id)
                                        >
                                            {{ $product->sku }} — {{ $product->name }}
                                            (Q {{ number_format((float) $product->sale_price, 2) }})
                                        </option>
                                    @endforeach
                                </select>
                            </td>

                            <td>
                                <input
                                    class="form-control quantity-input"
                                    type="number"
                                    name="lines[{{ $index }}][quantity]"
                                    value="{{ $line['quantity'] ?? '' }}"
                                    min="0.001"
                                    step="any"
                                    required
                                >
                            </td>

                            <td class="unit-label text-secondary">—</td>

                            <td>
                                <button
                                    class="btn btn-outline-danger btn-sm remove-line"
                                    type="button"
                                >
                                    Quitar
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if($products->isEmpty())
            <div class="alert alert-warning mb-0">
                No hay productos activos disponibles. Registra o activa productos antes de crear la venta.
            </div>
        @endif
    </div>

    <div class="d-flex justify-content-end gap-2 mb-4">
        <a
            class="btn btn-light"
            href="{{ $sale->exists ? route('sales.show', $sale) : route('sales.index') }}"
        >
            Cancelar
        </a>

        <button
            class="btn btn-primary"
            type="submit"
            {{ $products->isEmpty() ? 'disabled' : '' }}
        >
            {{ $sale->exists ? 'Guardar borrador' : 'Crear borrador' }}
        </button>
    </div>
</form>

<template id="sale-line-template">
    <tr class="sale-line">
        <td>
            <select class="form-select product-select" required>
                <option value="">Selecciona un producto</option>

                @foreach($products as $product)
                    <option
                        value="{{ $product->id }}"
                        data-unit="{{ $product->unit?->abbreviation ?? '' }}"
                        data-places="{{ $product->unit?->decimal_places ?? 0 }}"
                    >
                        {{ $product->sku }} — {{ $product->name }}
                        (Q {{ number_format((float) $product->sale_price, 2) }})
                    </option>
                @endforeach
            </select>
        </td>

        <td>
            <input
                class="form-control quantity-input"
                type="number"
                min="0.001"
                step="any"
                required
            >
        </td>

        <td class="unit-label text-secondary">—</td>

        <td>
            <button class="btn btn-outline-danger btn-sm remove-line" type="button">
                Quitar
            </button>
        </td>
    </tr>
</template>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const container = document.getElementById('sale-lines');
    const template = document.getElementById('sale-line-template');
    const addButton = document.getElementById('add-line');

    let nextIndex = {{ count($oldLines) }};

    function updateUnit(row) {
        const select = row.querySelector('.product-select');
        const input = row.querySelector('.quantity-input');
        const label = row.querySelector('.unit-label');
        const option = select.options[select.selectedIndex];

        label.textContent = option?.dataset.unit || '—';

        const places = Number(option?.dataset.places ?? 0);
        input.step = places === 0 ? '1' : '0.' + '0'.repeat(Math.max(0, places - 1)) + '1';
        input.min = places === 0 ? '1' : '0.' + '0'.repeat(Math.max(0, places - 1)) + '1';
    }

    function renumberLines() {
        container.querySelectorAll('.sale-line').forEach(function (row, index) {
            row.querySelector('.product-select').name = `lines[${index}][product_id]`;
            row.querySelector('.quantity-input').name = `lines[${index}][quantity]`;
        });

        nextIndex = container.querySelectorAll('.sale-line').length;
    }

    addButton.addEventListener('click', function () {
        const row = template.content.firstElementChild.cloneNode(true);

        row.querySelector('.product-select').name = `lines[${nextIndex}][product_id]`;
        row.querySelector('.quantity-input').name = `lines[${nextIndex}][quantity]`;

        container.appendChild(row);
        nextIndex++;

        updateUnit(row);
    });

    container.addEventListener('change', function (event) {
        if (event.target.matches('.product-select')) {
            updateUnit(event.target.closest('.sale-line'));
        }
    });

    container.addEventListener('click', function (event) {
        const button = event.target.closest('.remove-line');

        if (button) {
            button.closest('.sale-line').remove();
            renumberLines();
        }
    });

    container.querySelectorAll('.sale-line').forEach(updateUnit);
});
</script>
@endsection
