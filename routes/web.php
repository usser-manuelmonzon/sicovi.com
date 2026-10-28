<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CustomerController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\SupplierController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\SaleController;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\Subcategory;
use App\Models\Unit;
use Illuminate\Support\Facades\Route;

// 1. RUTAS PÚBLICAS (Invitados)
Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'create'])->name('login');
    Route::post('/login', [AuthController::class, 'store'])->name('login.store');
});

// 2. RUTAS PROTEGIDAS (Requieren sesión)
Route::middleware(['auth', 'active', 'auth.session'])->group(function () {
    Route::get('/', function () {
        $catalogs = [];
        foreach (['products' => [Product::class, 'Productos'], 'categories' => [Category::class, 'Categorías'], 'subcategories' => [Subcategory::class, 'Subcategorías'], 'brands' => [Brand::class, 'Marcas'], 'units' => [Unit::class, 'Presentaciones']] as $key => [$class, $name]) {
            if (auth()->user()->hasPermission($key.'.view')) {
                $catalogs[$key] = ['name' => $name, 'count' => $class::count()];
            }
        }

        return view('dashboard', compact('catalogs'));
    })->name('dashboard');

    Route::post('/logout', [AuthController::class, 'destroy'])->name('logout');
    Route::get('/account', [AccountController::class, 'edit'])->name('account.edit');
    Route::put('/account/password', [AccountController::class, 'updatePassword'])->name('account.password');

    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::patch('users/{user}/deactivate', [UserController::class, 'deactivate'])->name('users.deactivate');
    Route::put('users/{user}/password', [UserController::class, 'resetPassword'])->name('users.reset-password');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');

    Route::get('settings', [SettingsController::class, 'edit'])->name('settings.edit');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

    Route::resource('products', ProductController::class)->except('destroy');
    Route::patch('products/{product}/deactivate', [ProductController::class, 'deactivate'])->name('products.deactivate');

    Route::prefix('catalogs/{catalog}')->where(['catalog' => 'categories|subcategories|brands|units'])->group(function () {
        Route::get('/', [CatalogController::class, 'index'])->name('catalogs.index');
        Route::get('/create', [CatalogController::class, 'create'])->name('catalogs.create');
        Route::post('/', [CatalogController::class, 'store'])->name('catalogs.store');
        Route::get('/{id}/edit', [CatalogController::class, 'edit'])->whereNumber('id')->name('catalogs.edit');
        Route::put('/{id}', [CatalogController::class, 'update'])->whereNumber('id')->name('catalogs.update');
    });

    Route::resource('suppliers', SupplierController::class)->except('destroy');
    Route::patch('suppliers/{supplier}/deactivate', [SupplierController::class, 'deactivate'])->name('suppliers.deactivate');
    Route::resource('customers', CustomerController::class)->except('destroy');
    Route::patch('customers/{customer}/deactivate', [CustomerController::class, 'deactivate'])->name('customers.deactivate');
    Route::resource('purchases', PurchaseController::class)->except(['destroy', 'edit', 'update']);
    Route::post('purchases/{purchase}/confirm', [PurchaseController::class, 'confirm'])->name('purchases.confirm');
    Route::post('purchases/{purchase}/void', [PurchaseController::class, 'void'])->name('purchases.void');
    Route::resource('sales', SaleController::class)->except(['destroy']);
    Route::post('sales/{sale}/confirm', [SaleController::class, 'confirm'])->name('sales.confirm');
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventory/adjustments', [InventoryController::class, 'adjustments'])->name('inventory.adjustments.index');
    Route::get('inventory/adjustments/create', [InventoryController::class, 'createAdjustment'])->name('inventory.adjustments.create');
    Route::get('inventory/adjustments/{adjustment}', [InventoryController::class, 'showAdjustment'])->name('inventory.adjustments.show');
    Route::post('inventory/adjustments', [InventoryController::class, 'storeAdjustment'])->name('inventory.adjustments.store');
    Route::post('inventory/adjustments/{adjustment}/void', [InventoryController::class, 'voidAdjustment'])->name('inventory.adjustments.void');
});
