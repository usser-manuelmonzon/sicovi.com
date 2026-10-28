<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Paginator::useBootstrapFive();
        foreach ([
            'users.view', 'users.create', 'users.update', 'users.deactivate', 'users.reset_password',
            'roles.view', 'roles.manage', 'settings.view', 'settings.update',
            'products.view', 'products.create', 'products.update', 'products.deactivate', 'products.view_cost',
            'categories.view', 'categories.manage', 'subcategories.view', 'subcategories.manage',
            'brands.view', 'brands.manage', 'units.view', 'units.manage',
            'suppliers.view', 'suppliers.create', 'suppliers.update', 'suppliers.deactivate',
            'customers.view', 'customers.create', 'customers.update', 'customers.deactivate',
            'purchases.view', 'purchases.create', 'purchases.confirm', 'purchases.void',
            'inventory.view', 'inventory.adjust', 'inventory.void_adjustment', 'sales.view',
            'sales.create', 'sales.update', 'sales.confirm',
        ] as $ability) {
            Gate::define($ability, fn (User $user) => $user->hasPermission($ability));
        }
    }
}
