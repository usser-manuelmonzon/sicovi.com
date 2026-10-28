@extends('layouts.app')
@section('title', $customer->name)
@section('content')
<div class="page-heading">
    <div>
        <p class="eyebrow">CLIENTE {{ $customer->code }}</p>
        <h1>{{ $customer->name }}</h1>
        <p class="text-secondary">{{ $customer->tax_id ?: 'Sin NIT registrado' }}</p>
        <x-status :active="$customer->active" />
    </div>

    <div class="d-flex gap-2 flex-wrap">
        @can('customers.update')
            <a class="btn btn-outline-primary"
               href="{{ route('customers.edit', $customer) }}">Editar</a>
        @endcan

        @can('customers.deactivate')
            @if($customer->active)
                <form method="POST" action="{{ route('customers.deactivate', $customer) }}"
                      onsubmit="return confirm('¿Desea desactivar este cliente? Se conservará su historial.');">
                    @csrf
                    @method('PATCH')
                    <button class="btn btn-outline-danger" type="submit">Desactivar</button>
                </form>
            @endif
        @endcan

        <a class="btn btn-light" href="{{ route('customers.index') }}">Volver</a>
    </div>
</div>

<div class="surface p-4">
    <h2 class="h5 mb-4">Información del cliente</h2>
    <div class="row g-4">
        <div class="col-md-4">
            <strong>Código</strong>
            <div>{{ $customer->code }}</div>
        </div>
        <div class="col-md-4">
            <strong>NIT</strong>
            <div>{{ $customer->tax_id ?: '—' }}</div>
        </div>
        <div class="col-md-4">
            <strong>Estado</strong>
            <div>{{ $customer->active ? 'Activo' : 'Inactivo' }}</div>
        </div>
        <div class="col-md-6">
            <strong>Teléfono</strong>
            <div>{{ $customer->phone ?: '—' }}</div>
        </div>
        <div class="col-md-6">
            <strong>Correo electrónico</strong>
            <div>{{ $customer->email ?: '—' }}</div>
        </div>
        <div class="col-12">
            <strong>Dirección</strong>
            <div>{{ $customer->address ?: '—' }}</div>
        </div>
        <div class="col-md-6">
            <strong>Creado</strong>
            <div>{{ $customer->created_at?->format('d/m/Y H:i') ?: '—' }}</div>
        </div>
        <div class="col-md-6">
            <strong>Última actualización</strong>
            <div>{{ $customer->updated_at?->format('d/m/Y H:i') ?: '—' }}</div>
        </div>
    </div>
</div>
@endsection