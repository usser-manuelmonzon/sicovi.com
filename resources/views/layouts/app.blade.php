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
    $inventarioActivo = request()->routeIs('products.*')
        || (request()->routeIs('catalogs.*')
            && in_array($catalogoActual, ['categories', 'subcategories', 'brands', 'units'], true));
    $seguridadActiva = request()->routeIs('users.*') || request()->routeIs('roles.*');
    $configuracionActiva = request()->routeIs('settings.*');
    $comprasActivo = request()->routeIs('customers.*')
    || request()->routeIs('suppliers.*')
    || request()->routeIs('purchases.*')
    || request()->routeIs('sales.*');
    $inventarioOperativoActivo = request()->routeIs('inventory.*');
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
                <details class="sc-group" {{ $inventarioActivo ? 'open' : '' }}>
                    <summary class="{{ $inventarioActivo ? 'is-active' : '' }}" title="Inventario">
                        <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <rect x="3" y="3" width="7" height="7" rx="1"/>
                            <rect x="14" y="3" width="7" height="7" rx="1"/>
                            <rect x="3" y="14" width="7" height="7" rx="1"/>
                            <rect x="14" y="14" width="7" height="7" rx="1"/>
                        </svg>
                        <span class="sc-label">Inventario</span>
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

            @canany(['customers.view', 'suppliers.view', 'purchases.view', 'inventory.view', 'sales.view'])
                <details class="sc-group" {{ ($comprasActivo || $inventarioOperativoActivo) ? 'open' : '' }}>
                    <summary class="{{ ($comprasActivo || $inventarioOperativoActivo) ? 'is-active' : '' }}" title="Compras e inventario operativo">
                        <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 4h16v16H4zM8 8h8M8 12h8M8 16h5"/></svg>
                        <span class="sc-label">Operaciones</span>
                        <svg class="sc-chevron" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 18 6-6-6-6"/></svg>
                    </summary>
                    <div class="sc-submenu">
@can('customers.view')
    <a href="{{ route('customers.index') }}"
       class="{{ request()->routeIs('customers.*') ? 'is-active' : '' }}">
        Clientes
    </a>
@endcan
@can('suppliers.view')
    <a href="{{ route('suppliers.index') }}"
       class="{{ request()->routeIs('suppliers.*') ? 'is-active' : '' }}">
        Proveedores
    </a>
@endcan
                        @can('purchases.view')<a href="{{ route('purchases.index') }}" class="{{ request()->routeIs('purchases.*') ? 'is-active' : '' }}">Compras</a>@endcan
                        @can('sales.view')<a href="{{ route('sales.index') }}" class="{{ request()->routeIs('sales.*') ? 'is-active' : '' }}">Ventas</a>@endcan
                        @can('inventory.view')<a href="{{ route('inventory.index') }}" class="{{ request()->routeIs('inventory.*') ? 'is-active' : '' }}">Existencias y kardex</a>@endcan
                        @can('inventory.view')<a href="{{ route('inventory.adjustments.index') }}" class="{{ request()->routeIs('inventory.adjustments.*') && !request()->routeIs('inventory.adjustments.create') ? 'is-active' : '' }}">Historial de ajustes</a>@endcan
                        @can('inventory.adjust')<a href="{{ route('inventory.adjustments.create') }}">Registrar ajuste</a>@endcan
                    </div>
                </details>
            @endcanany

            @canany(['users.view', 'roles.view'])
                <details class="sc-group" {{ $seguridadActiva ? 'open' : '' }}>
                    <summary class="{{ $seguridadActiva ? 'is-active' : '' }}" title="Seguridad">
                        <svg class="sc-icon" viewBox="0 0 24 24" aria-hidden="true">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                            <rect x="9" y="10" width="6" height="6" rx="1"/>
                            <path d="M10.5 10V8.5a1.5 1.5 0 0 1 3 0V10"/>
                        </svg>
                        <span class="sc-label">Seguridad</span>
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
                                Roles
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
                            Empresa
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
