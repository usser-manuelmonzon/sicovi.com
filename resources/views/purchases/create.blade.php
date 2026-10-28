@extends('layouts.app')

@section('content')
<div class="container-fluid px-3 py-4">
    <h2 class="fw-bold mb-4" style="color: #092E54;">Nuevo Borrador de Compra</h2>

    @if($errors->any())
        <div class="alert alert-danger border-0">
            <ul class="mb-0">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
    @endif

    <form action="{{ route('purchases.store') }}" method="POST">
        @csrf

        <!-- Encabezado -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Proveedor</label>
                        <select name="supplier_id" class="form-select" required>
                            <option value="">Seleccione proveedor...</option>
                            @foreach($suppliers as $supplier)
                                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Fecha del Documento</label>
                        <input type="date" name="document_date" class="form-control" value="{{ date('Y-m-d') }}" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Referencia (Opcional)</label>
                        <input type="text" name="reference" class="form-control" placeholder="No. Factura/Envío">
                    </div>
                </div>
            </div>
        </div>

        <!-- Detalle Dinámico -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-white border-bottom-0 pt-4 pb-0 d-flex justify-content-between">
                <h5 class="fw-bold" style="color: #092E54;">Líneas de Mercadería</h5>
                <button type="button" class="btn btn-sm text-white" style="background-color: #06A8A9;" onclick="addLine()">
                    + Agregar Producto
                </button>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover align-middle" id="linesTable">
                        <thead style="background-color: #EDF6FB; color: #092E54;">
                            <tr>
                                <th>Producto</th>
                                <th style="width: 15%">Cantidad</th>
                                <th style="width: 15%">Costo (GTQ)</th>
                                <th style="width: 15%">Subtotal</th>
                                <th style="width: 5%"></th>
                            </tr>
                        </thead>
                        <tbody id="linesBody">
                            <!-- JS inserta filas aquí -->
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="d-flex justify-content-end gap-2">
            <a href="{{ url('/purchases') }}" class="btn btn-light border">Cancelar</a>
            <button type="submit" class="btn text-white px-4" style="background-color: #2166E5;">Guardar Borrador</button>
        </div>
    </form>
</div>

<!-- Plantilla oculta para JS -->
<table style="display: none;">
    <tbody id="rowTemplate">
        <tr>
            <td>
                <select name="lines[INDEX][product_id]" class="form-select product-select" required onchange="calculateRow(this)">
                    <option value="">Seleccionar...</option>
                    @foreach($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }}</option>
                    @endforeach
                </select>
            </td>
            <td>
                <input type="number" step="0.001" name="lines[INDEX][quantity]" class="form-control qty-input" value="1" required oninput="calculateRow(this)">
            </td>
            <td>
                <input type="number" step="0.01" name="lines[INDEX][unit_cost]" class="form-control cost-input" value="0.00" required oninput="calculateRow(this)">
            </td>
            <td class="fw-bold subtotal-text">0.00</td>
            <td>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeLine(this)">X</button>
            </td>
        </tr>
    </tbody>
</table>

<script>
    let lineIndex = 0;

    function addLine() {
        const tbody = document.getElementById('linesBody');
        const template = document.getElementById('rowTemplate').innerHTML;
        const newRow = template.replace(/INDEX/g, lineIndex++);
        tbody.insertAdjacentHTML('beforeend', newRow);
    }

    function removeLine(button) {
        button.closest('tr').remove();
    }

    function calculateRow(element) {
        const row = element.closest('tr');
        const qty = parseFloat(row.querySelector('.qty-input').value) || 0;
        const cost = parseFloat(row.querySelector('.cost-input').value) || 0;
        row.querySelector('.subtotal-text').innerText = (qty * cost).toFixed(2);
    }

    // Agregar primera línea por defecto
    window.onload = addLine;
</script>
@endsection