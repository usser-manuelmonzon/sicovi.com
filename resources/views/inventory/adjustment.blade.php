@extends('layouts.app')
@section('title', 'Registrar ajuste')
@section('content')
<div class="page-heading"><div><p class="eyebrow">INVENTARIO</p><h1>Registrar entrada o ajuste</h1><p class="text-secondary">El motivo y las cantidades se validan en el servidor.</p></div></div>
<form method="POST" action="{{ route('inventory.adjustments.store') }}" class="surface p-4">@csrf
<input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
<div class="row g-3"><div class="col-md-4"><label class="form-label" for="type">Tipo</label><select class="form-select" id="type" name="type" required><option value="opening">Inventario inicial</option><option value="extraordinary_entry">Entrada extraordinaria</option><option value="count_adjustment">Ajuste por conteo</option></select></div><div class="col-md-4"><label class="form-label" for="adjustment_date">Fecha</label><input class="form-control" type="date" id="adjustment_date" name="adjustment_date" value="{{ now()->format('Y-m-d') }}" required></div><div class="col-md-4"><label class="form-label" for="reference">Referencia</label><input class="form-control" id="reference" name="reference"></div><div class="col-12"><label class="form-label" for="reason">Motivo *</label><textarea class="form-control" id="reason" name="reason" required></textarea></div></div>
<div class="row g-3 mt-4 align-items-end"><div class="col-md-8"><label class="form-label" for="product-picker">Producto</label><select class="form-select" id="product-picker"><option value="">Seleccione un producto...</option>@foreach($products as $product)<option value="{{ $product->id }}" data-name="{{ $product->name }}" data-unit="{{ $product->unit->abbreviation }}">{{ $product->name }} ({{ $product->unit->abbreviation }})</option>@endforeach</select></div><div class="col-md-4"><button class="btn btn-outline-primary w-100" type="button" id="add-adjustment-line">Agregar producto</button></div></div>
<div class="table-responsive mt-4"><table class="table align-middle"><thead><tr><th>Producto</th><th>Cantidad de entrada</th><th>Cantidad observada</th><th></th></tr></thead><tbody id="adjustment-lines"><tr id="empty-adjustment-lines"><td colspan="4" class="text-secondary">Agregue los productos que participarán en el ajuste.</td></tr></tbody></table></div>
<div class="mt-4 d-flex flex-wrap gap-2"><button class="btn btn-primary">Aplicar ajuste</button><a class="btn btn-light" href="{{ route('inventory.index') }}">Cancelar</a></div></form>
@push('scripts')
<script>
(() => {
    const picker = document.getElementById('product-picker');
    const lines = document.getElementById('adjustment-lines');
    const empty = document.getElementById('empty-adjustment-lines');
    const added = new Set();
    let lineIndex = 0;
    document.getElementById('add-adjustment-line').addEventListener('click', () => {
        const option = picker.options[picker.selectedIndex];
        if (!option.value || added.has(option.value)) return;
        added.add(option.value);
        empty?.remove();
        const index = lineIndex++;
        const row = document.createElement('tr');
        row.dataset.productId = option.value;
        row.innerHTML = `<td>${option.dataset.name} <span class="small text-secondary">(${option.dataset.unit})</span><input type="hidden" name="lines[${index}][product_id]" value="${option.value}"></td><td><input class="form-control" name="lines[${index}][quantity]" placeholder="0.000"></td><td><input class="form-control" name="lines[${index}][counted_quantity]" placeholder="Solo para conteo"></td><td><button class="btn btn-sm btn-outline-danger remove-line" type="button">Quitar</button></td>`;
        row.querySelector('.remove-line').addEventListener('click', () => { added.delete(option.value); row.remove(); });
        lines.appendChild(row);
        picker.value = '';
    });
})();
</script>
@endpush
@endsection
