@extends('layouts.app')

@section('content')
<div class="container-fluid px-3 py-4">
    <!-- Encabezado y Estado -->
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center mb-4">
        <div>
            <h2 class="fw-bold mb-1" style="color: #092E54;">
                Compra: {{ $purchase->code ?? 'Borrador' }}
            </h2>
            <p class="text-muted mb-0">Proveedor: {{ $purchase->supplier->name ?? 'N/A' }}</p>
        </div>
        <div class="mt-3 mt-md-0">
            <!-- Cambiamos a DRAFT -->
            <span class="badge px-3 py-2" style="background-color: {{ strtoupper($purchase->state) === 'DRAFT' ? '#06A8A9' : '#092E54' }};">
                {{ strtoupper($purchase->state) }}
            </span>
        </div>
    </div>

    <!-- Mensajes de Validación -->
    @if(session('success'))
        <div class="alert alert-success border-0 bg-success text-white">
            {{ session('success') }}
        </div>
    @endif

    @if($errors->any())
        <div class="alert alert-danger border-0">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <!-- Tarjeta de Detalles y Tabla de Productos -->
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-white border-bottom-0 pt-4 pb-0 px-4">
            <h5 class="fw-bold" style="color: #092E54;">Detalle de Mercadería</h5>
        </div>
        <div class="card-body px-4">

            <form action="{{ route('purchases.confirm', $purchase->id) }}" method="POST" id="confirmPurchaseForm">
                @csrf
                <input type="hidden" name="idempotency_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

                <div class="table-responsive mb-4">
                    <table class="table table-hover align-middle">
                        <thead style="background-color: #EDF6FB; color: #092E54;">
                            <tr>
                                <th>Producto</th>
                                <th>Cantidad</th>
                                <th>Costo Unitario (GTQ)</th>
                                <th>Total de Línea (GTQ)</th>
                            </tr>
                        </thead>
                        <tbody>
                            @forelse($purchase->items as $index => $item)
                                <tr>
                                    <td>
                                        {{ $item->product->name ?? $item->product_name ?? 'Producto desconocido' }}
                                        <input type="hidden" name="lines[{{ $index }}][product_id]" value="{{ $item->product_id }}">
                                    </td>
                                    <td>
                                        <!-- Cambiamos a DRAFT -->
                                        <input type="number" step="0.001" name="lines[{{ $index }}][quantity]"
                                               class="form-control form-control-sm w-75"
                                               value="{{ $item->quantity }}"
                                               {{ $purchase->state !== 'draft' ? 'readonly' : 'required' }}>
                                    </td>
                                    <td>
                                        <!-- Cambiamos a DRAFT -->
                                        <input type="number" step="0.01" name="lines[{{ $index }}][unit_cost]"
                                               class="form-control form-control-sm w-75"
                                               value="{{ $item->unit_cost }}"
                                               {{ $purchase->state !== 'draft' ? 'readonly' : 'required' }}>
                                    </td>
                                    <td>
                                        {{ number_format($item->line_total, 2) }}
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="4" class="text-center text-muted py-4">No hay líneas registradas en este borrador.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <!-- Botonera -->
                <div class="d-flex justify-content-end gap-2">
                    <a href="{{ route('purchases.index') ?? url('/purchases') }}" class="btn btn-light border">Volver al listado</a>

                    <!-- Cambiamos a DRAFT -->
                    @if($purchase->state === 'draft' && $purchase->items->count() > 0)
                        <button type="submit" class="btn text-white px-4" style="background-color: #2166E5;"
                                onclick="this.disabled=true; this.innerText='Procesando...'; this.form.submit();">
                            Confirmar Recepción
                        </button>
                    @endif
                </div>
            </form>
            @if($purchase->state === 'confirmed')
                @can('purchases.void')
                    <form action="{{ route('purchases.void', $purchase) }}" method="POST" class="mt-3">
                        @csrf
                        <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                        <label class="form-label" for="void-reason">Motivo de anulación</label>
                        <textarea class="form-control mb-2" id="void-reason" name="reason" required></textarea>
                        <button type="submit" class="btn btn-outline-danger">Anular compra y revertir recepción</button>
                    </form>
                @endcan
            @endif
        </div>
    </div>
</div>
@endsection