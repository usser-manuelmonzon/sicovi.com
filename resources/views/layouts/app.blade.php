<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'SICOVI') · Gestión comercial</title>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="sc-body">
<a class="skip-link" href="#main">Ir al contenido</a>

@php
    $catalogoActual = request()->route('catalog');
    $almacenCentralActivo = request()->routeIs('products.*')
        || (request()->routeIs('catalogs.*')
            && in_array($catalogoActual, ['categories', 'subcategories', 'brands', 'units'], true));
    $administracionActiva = request()->routeIs('users.*') || request()->routeIs('roles.*');
    $configuracionActiva = request()->routeIs('settings.*');
    $comprasActivo = request()->routeIs('suppliers.*') || request()->routeIs('purchases.*');
    $ventasActiva = request()->routeIs('customers.*') || request()->routeIs('sales.*');
    $inventarioOperativoActivo = request()->routeIs('inventory.*');
    $reportesActivo = request()->routeIs('reports.*');
    $reporteActual = request('report', 'sales');
@endphp

<div class="sc-shell">
    <aside class="sc-sidebar" id="sicoviSidebar" aria-label="Menú principal">
        <div class="sc-sidebar__header">
            {{-- Dos archivos independientes: logotipo expandido e isotipo contraído. --}}
            <a href="{{ route('dashboard') }}" class="sc-brand" aria-label="SICOVI, ir al inicio">
                <img class="sc-brand__full"
                     src="{{ asset('images/sicovi-logo.png') }}"
                     alt="SICOVI">
                <img class="sc-brand__icon"
                     src="{{ asset('images/sicovi-logo2.png') }}"
                     alt="" aria-hidden="true">
            </a>

            <button type="button" class="sc-collapse" id="sidebarCollapse"
                    aria-controls="sicoviSidebar" aria-expanded="true"
                    aria-label="Contraer menú" title="Contraer o expandir menú">
                <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m15 18-6-6 6-6" />
                </svg>
            </button>

            <button type="button" class="sc-close" id="sidebarClose"
                    aria-label="Cerrar menú">
                <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M18 6 6 18M6 6l12 12" />
                </svg>
            </button>
        </div>

        <nav class="sc-nav" aria-label="Módulos y mantenimientos">
            <a href="{{ route('dashboard') }}"
               class="sc-nav__item {{ request()->routeIs('dashboard') ? 'is-active' : '' }}"
               title="Inicio"
               @if(request()->routeIs('dashboard')) aria-current="page" @endif>
                <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="m3 10 9-7 9 7v10H3zM9 20v-7h6v7"/>
                </svg>
                <span class="sc-label">Inicio</span>
            </a>

            @canany(['products.view', 'categories.view', 'subcategories.view', 'brands.view', 'units.view'])
                <details class="sc-group" {{ $almacenCentralActivo ? 'open' : '' }}>
                    <summary class="{{ $almacenCentralActivo ? 'is-active' : '' }}" title="Almacén Central">
                        <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="7" rx="1"/>
                            <rect x="14" y="3" width="7" height="7" rx="1"/>
                            <rect x="3" y="14" width="7" height="7" rx="1"/>
                            <rect x="14" y="14" width="7" height="7" rx="1"/>
                        </svg>
                        <span class="sc-label">Almacén Central</span>
                        <svg class="sc-chevron" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="m9 18 6-6-6-6"/>
                        </svg>
                    </summary>

                    <div class="sc-submenu">
                        @can('products.view')
                            <a href="{{ route('products.index') }}"
                               class="{{ request()->routeIs('products.*') ? 'is-active' : '' }}"
                               @if(request()->routeIs('products.*')) aria-current="page" @endif>
                                Productos
                            </a>
                        @endcan

                        @foreach ([
                            'categories' => 'Categorías',
                            'subcategories' => 'Subcategorías',
                            'brands' => 'Marcas',
                            'units' => 'Presentaciones'
                        ] as $key => $label)
                            @can($key.'.view')
                                <a href="{{ route('catalogs.index', $key) }}"
                                   class="{{ request()->routeIs('catalogs.*') && $catalogoActual === $key ? 'is-active' : '' }}"
                                   @if(request()->routeIs('catalogs.*') && $catalogoActual === $key) aria-current="page" @endif>
                                    {{ $label }}
                                </a>
                            @endcan
                        @endforeach
                    </div>
                </details>
            @endcanany

            @canany(['suppliers.view', 'purchases.view', 'purchases.create'])
                <details class="sc-group" {{ $comprasActivo ? 'open' : '' }}>
                    <summary class="{{ $comprasActivo ? 'is-active' : '' }}" title="Compras">
                        <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 1.9-1.4L22 9H6M10 21h.01M18 21h.01"/></svg>
                        <span class="sc-label">Compras</span>
                        <svg class="sc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </summary>
                    <div class="sc-submenu">
                        @can('suppliers.view')<a href="{{ route('suppliers.index') }}" class="{{ request()->routeIs('suppliers.*') ? 'is-active' : '' }}" @if(request()->routeIs('suppliers.*')) aria-current="page" @endif>Proveedores</a>@endcan
                        @can('purchases.create')<a href="{{ route('purchases.create') }}" class="{{ request()->routeIs('purchases.create') ? 'is-active' : '' }}" @if(request()->routeIs('purchases.create')) aria-current="page" @endif>Registrar compra</a>@endcan
                        @can('purchases.view')<a href="{{ route('purchases.index') }}" class="{{ request()->routeIs('purchases.index', 'purchases.show') ? 'is-active' : '' }}" @if(request()->routeIs('purchases.index', 'purchases.show')) aria-current="page" @endif>Consultar compras</a>@endcan
                    </div>
                </details>
            @endcanany

            @canany(['customers.view', 'sales.view', 'sales.create'])
                <details class="sc-group" {{ $ventasActiva ? 'open' : '' }}>
                    <summary class="{{ $ventasActiva ? 'is-active' : '' }}" title="Ventas">
                        <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M3 4h2l2.2 11.2a2 2 0 0 0 2 1.6h8.9a2 2 0 0 0 1.9-1.4L22 9H6M10 21h.01M18 21h.01"/></svg>
                        <span class="sc-label">Ventas</span>
                        <svg class="sc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </summary>
                    <div class="sc-submenu">
                        @can('customers.view')<a href="{{ route('customers.index') }}" class="{{ request()->routeIs('customers.*') ? 'is-active' : '' }}" @if(request()->routeIs('customers.*')) aria-current="page" @endif>Clientes</a>@endcan
                        @can('sales.create')<a href="{{ route('sales.create') }}" class="{{ request()->routeIs('sales.create') ? 'is-active' : '' }}" @if(request()->routeIs('sales.create')) aria-current="page" @endif>Registrar venta</a>@endcan
                        @can('sales.view')<a href="{{ route('sales.index') }}" class="{{ request()->routeIs('sales.index', 'sales.show', 'sales.edit') ? 'is-active' : '' }}" @if(request()->routeIs('sales.index', 'sales.show', 'sales.edit')) aria-current="page" @endif>Consultar ventas</a>@endcan
                    </div>
                </details>
            @endcanany

            @canany(['inventory.view', 'inventory.adjust'])
                <details class="sc-group" {{ $inventarioOperativoActivo ? 'open' : '' }}>
                    <summary class="{{ $inventarioOperativoActivo ? 'is-active' : '' }}" title="Inventario">
                        <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="m3 7 9-4 9 4-9 4-9-4Zm0 5 9 4 9-4M3 17l9 4 9-4"/></svg>
                        <span class="sc-label">Inventario</span>
                        <svg class="sc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </summary>
                    <div class="sc-submenu">
                        @can('inventory.adjust')<a href="{{ route('inventory.adjustments.create') }}" class="{{ request()->routeIs('inventory.adjustments.create') ? 'is-active' : '' }}" @if(request()->routeIs('inventory.adjustments.create')) aria-current="page" @endif>Registrar ajuste</a>@endcan
                        @can('inventory.view')<a href="{{ route('inventory.index') }}" class="{{ request()->routeIs('inventory.index') ? 'is-active' : '' }}" @if(request()->routeIs('inventory.index')) aria-current="page" @endif>Existencias y movimientos</a>@endcan
                        @can('inventory.view')<a href="{{ route('inventory.adjustments.index') }}" class="{{ request()->routeIs('inventory.adjustments.*') && !request()->routeIs('inventory.adjustments.create') ? 'is-active' : '' }}" @if(request()->routeIs('inventory.adjustments.*') && !request()->routeIs('inventory.adjustments.create')) aria-current="page" @endif>Historial de ajustes</a>@endcan
                    </div>
                </details>
            @endcanany

            @can('reports.view')
                <details class="sc-group" {{ $reportesActivo ? 'open' : '' }}>
                    <summary class="{{ $reportesActivo ? 'is-active' : '' }}" title="Reportes">
                        <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 19V5m0 14h17M8 16v-5m5 5V7m5 9V3"/></svg>
                        <span class="sc-label">Reportes</span>
                        <svg class="sc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </summary>
                    <div class="sc-submenu">
                        @foreach (['sales' => 'Reporte de ventas', 'purchases' => 'Reporte de compras', 'inventory' => 'Reporte de existencias', 'kardex' => 'Reporte Kardex', 'cancellations' => 'Reporte de anulaciones'] as $reportKey => $reportLabel)
                            <a href="{{ route('reports.index', ['report' => $reportKey]) }}" class="{{ $reportesActivo && $reporteActual === $reportKey ? 'is-active' : '' }}" @if($reportesActivo && $reporteActual === $reportKey) aria-current="page" @endif>{{ $reportLabel }}</a>
                        @endforeach
                    </div>
                </details>
            @endcan

            @canany(['users.view', 'roles.view'])
                <details class="sc-group" {{ $administracionActiva ? 'open' : '' }}>
                    <summary class="{{ $administracionActiva ? 'is-active' : '' }}" title="Administración">
                        <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            <rect x="9" y="10" width="6" height="6" rx="1"/>
                            <path d="M10.5 10V8.5a1.5 1.5 0 0 1 3 0V10"/>
                        </svg>
                        <span class="sc-label">Administración</span>
                        <svg class="sc-chevron" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="m9 18 6-6-6-6"/>
                        </svg>
                    </summary>
                    <div class="sc-submenu">
                        @can('users.view')
                            <a href="{{ route('users.index') }}"
                               class="{{ request()->routeIs('users.*') ? 'is-active' : '' }}"
                               @if(request()->routeIs('users.*')) aria-current="page" @endif>
                                Usuarios
                            </a>
                        @endcan
                        @can('roles.view')
                            <a href="{{ route('roles.index') }}"
                               class="{{ request()->routeIs('roles.*') ? 'is-active' : '' }}"
                               @if(request()->routeIs('roles.*')) aria-current="page" @endif>
                                Roles y permisos
                            </a>
                        @endcan
                    </div>
                </details>
            @endcanany

            @can('settings.view')
                <details class="sc-group" {{ $configuracionActiva ? 'open' : '' }}>
                    <summary class="{{ $configuracionActiva ? 'is-active' : '' }}" title="Configuración">
                        <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M4 7h16M4 17h16M8 4v6M16 14v6"/>
                            <circle cx="8" cy="7" r="2"/>
                            <circle cx="16" cy="17" r="2"/>
                        </svg>
                        <span class="sc-label">Configuración</span>
                        <svg class="sc-chevron" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="m9 18 6-6-6-6"/>
                        </svg>
                    </summary>
                    <div class="sc-submenu">
                        <a href="{{ route('settings.edit') }}"
                           class="{{ $configuracionActiva ? 'is-active' : '' }}"
                           @if($configuracionActiva) aria-current="page" @endif>
                            Datos de la empresa
                        </a>
                    </div>
                </details>
            @endcan
        </nav>

        <div class="sc-sidebar__footer">
            <a href="{{ route('account.edit') }}"
               class="sc-nav__item {{ request()->routeIs('account.*') ? 'is-active' : '' }}"
               title="Mi cuenta">
                <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <circle cx="12" cy="8" r="4"/>
                    <path d="M4 21v-2a8 8 0 0 1 16 0v2"/>
                </svg>
                <span class="sc-label">Mi cuenta</span>
            </a>
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" class="sc-nav__item sc-logout" title="Salir">
                    <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                        <path d="M10 17l5-5-5-5M15 12H3"/>
                        <path d="M13 3h7v18h-7"/>
                    </svg>
                    <span class="sc-label">Salir</span>
                </button>
            </form>
        </div>
    </aside>

    <button type="button" class="sc-overlay" id="sidebarOverlay"
            aria-label="Cerrar menú" tabindex="-1"></button>

    <div class="sc-main">
        <header class="sc-topbar">
            <button type="button" class="sc-mobile-open" id="sidebarOpen"
                    aria-controls="sicoviSidebar" aria-expanded="false"
                    aria-label="Abrir menú">
                <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                    <path d="M4 6h16M4 12h16M4 18h16"/>
                </svg>
            </button>
            <strong class="sc-topbar__title">@yield('title', 'Inicio')</strong>
            <a href="{{ route('account.edit') }}" class="sc-topbar__user">
                {{ auth()->user()->name }}
            </a>
        </header>

        <main id="main" class="container-fluid main-content" tabindex="-1">
            @if(session('status'))
                <div class="alert alert-success" role="status">{{ session('status') }}</div>
            @endif

            @if(session('success'))
                <div class="alert alert-success" role="status">{{ session('success') }}</div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger" role="alert">
                    <strong>Revise los datos para continuar.</strong>
                    <ul class="mb-0 mt-2">
                        @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                        @endforeach
                    </ul>
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="app-footer">
            <span>SICOVI</span> Sistema Integrado de Compras, Ventas e Inventario
        </footer>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const body = document.body;
    const sidebar = document.getElementById('sicoviSidebar');
    const appMain = document.querySelector('.sc-main');
    const collapse = document.getElementById('sidebarCollapse');
    const open = document.getElementById('sidebarOpen');
    const close = document.getElementById('sidebarClose');
    const overlay = document.getElementById('sidebarOverlay');
    const mobile = window.matchMedia('(max-width: 991px)');

    function setMobileOpen(value) {
        const isOpen = mobile.matches && value;
        body.classList.toggle('sc-mobile-opened', isOpen);
        open.setAttribute('aria-expanded', String(isOpen));
        sidebar.inert = mobile.matches && !isOpen;
        appMain.inert = isOpen;
        if (isOpen) close.focus();
    }

    function setCollapsed(value) {
        body.classList.toggle('sc-collapsed', value);
        collapse.setAttribute('aria-expanded', String(!value));
        collapse.setAttribute('aria-label', value ? 'Expandir menú' : 'Contraer menú');
        try {
            localStorage.setItem('sicovi-sidebar-collapsed', value ? '1' : '0');
        } catch (_) {}
    }

    try {
        setCollapsed(localStorage.getItem('sicovi-sidebar-collapsed') === '1');
    } catch (_) {
        setCollapsed(false);
    }

    open.addEventListener('click', () => setMobileOpen(true));
    close.addEventListener('click', () => { setMobileOpen(false); open.focus(); });
    overlay.addEventListener('click', () => { setMobileOpen(false); open.focus(); });
    collapse.addEventListener('click', () => setCollapsed(!body.classList.contains('sc-collapsed')));

    sidebar.querySelectorAll('.sc-group > summary').forEach(summary => {
        summary.addEventListener('click', event => {
            if (!mobile.matches && body.classList.contains('sc-collapsed')) {
                event.preventDefault();
                setCollapsed(false);
                summary.parentElement.open = true;
            }
        });
    });

    sidebar.querySelectorAll('a').forEach(link => {
        link.addEventListener('click', () => {
            if (mobile.matches) setMobileOpen(false);
        });
    });

    document.addEventListener('keydown', event => {
        if (!body.classList.contains('sc-mobile-opened')) return;
        if (event.key === 'Escape') {
            setMobileOpen(false);
            open.focus();
            return;
        }
        if (event.key !== 'Tab') return;
        const focusable = Array.from(sidebar.querySelectorAll('a, button, summary'))
            .filter(el => !el.disabled && el.getClientRects().length > 0 && !el.closest('details:not([open])'));
        if (focusable.length === 0) return;
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        if (event.shiftKey && document.activeElement === first) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && document.activeElement === last) {
            event.preventDefault();
            first.focus();
        }
    });

    mobile.addEventListener('change', () => setMobileOpen(false));
    setMobileOpen(false);
});
</script>
@stack('scripts')
</body>
</html>
