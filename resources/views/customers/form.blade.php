@extends('layouts.app')
@section('title', $customer->exists ? 'Editar cliente' : 'Nuevo cliente')
@section('content')
<div class="page-heading">
    <div>
        <p class="eyebrow">VENTAS</p>
        <h1>{{ $customer->exists ? 'Editar cliente' : 'Nuevo cliente' }}</h1>
        <p class="text-secondary">Los campos marcados con * son obligatorios.</p>
    </div>
</div>

<form method="POST"
      action="{{ $customer->exists ? route('customers.update', $customer) : route('customers.store') }}"
      class="surface p-4">
    @csrf
    @if($customer->exists)
        @method('PUT')
    @endif

    <div class="row g-3">
        <div class="col-md-8">
            <label class="form-label" for="name">Nombre o razón social *</label>
            <input class="form-control @error('name') is-invalid @enderror"
                   id="name" name="name" maxlength="180"
                   value="{{ old('name', $customer->name) }}" required>
            @error('name')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-md-4">
            <label class="form-label" for="tax_id">NIT</label>
            <input class="form-control @error('tax_id') is-invalid @enderror"
                   id="tax_id" name="tax_id" maxlength="30"
                   value="{{ old('tax_id', $customer->tax_id) }}">
            @error('tax_id')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <div class="form-text">Opcional. No se permiten NIT duplicados.</div>
        </div>

        <div class="col-md-6">
            <label class="form-label" for="phone">Teléfono</label>
            <input class="form-control @error('phone') is-invalid @enderror"
                   id="phone" name="phone" maxlength="30"
                   value="{{ old('phone', $customer->phone) }}">
            @error('phone')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-md-6">
            <label class="form-label" for="email">Correo electrónico</label>
            <input class="form-control @error('email') is-invalid @enderror"
                   type="email" id="email" name="email" maxlength="254"
                   value="{{ old('email', $customer->email) }}">
            @error('email')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>

        <div class="col-12">
            <label class="form-label" for="address">Dirección</label>
            <textarea class="form-control @error('address') is-invalid @enderror"
                      id="address" name="address" rows="3"
                      maxlength="5000">{{ old('address', $customer->address) }}</textarea>
            @error('address')<div class="invalid-feedback">{{ $message }}</div>@enderror
        </div>
    </div>

    <div class="mt-4 d-flex gap-2">
        <button class="btn btn-primary" type="submit">Guardar cliente</button>
        <a class="btn btn-light"
           href="{{ $customer->exists ? route('customers.show', $customer) : route('customers.index') }}">
            Cancelar
        </a>
    </div>
</form>
@endsection