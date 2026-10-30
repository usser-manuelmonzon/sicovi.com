<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Permission;
use App\Models\Product;
use App\Models\User;
use App\Services\AccessService;
use App\Services\AuditService;
use App\Support\DeploymentPreflight;
use Database\Seeders\CloudDemoSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Cache\RateLimiter;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\DatabaseTestCase;

class CloudDeploymentTest extends DatabaseTestCase
{
    private function administrator(): User
    {
        return app(AccessService::class)->createAdministrator([
            'name' => 'Administrador de verificación', 'username' => 'cloud-admin',
            'email' => 'cloud-admin@example.test', 'password' => 'Fixture-only-cloud-2026!',
        ]);
    }

    public function test_preflight_is_read_only_and_checks_the_real_postgresql_database(): void
    {
        $before = DB::table('audit_events')->count();
        $this->artisan('sicovi:preparar-despliegue')->assertSuccessful();
        $this->assertSame(0, User::count());
        $this->assertSame(0, Product::count());
        $this->assertSame($before, DB::table('audit_events')->count());
        $this->assertSame('sicovi_test', app(DeploymentPreflight::class)->inspect()['database']);
    }

    public function test_explicit_preparation_is_repeatable_without_restoring_revoked_permissions_or_settings(): void
    {
        $admin = $this->administrator();
        $removed = Permission::where('code', 'products.deactivate')->sole();
        $admin->role->permissions()->detach($removed);
        Company::sole()->update(['trade_name' => 'Nombre conservado']);
        $hash = $admin->password;
        $this->artisan('sicovi:preparar-despliegue', ['--aplicar' => true])->assertSuccessful();
        $this->artisan('sicovi:preparar-despliegue', ['--aplicar' => true])->assertSuccessful();
        $this->assertSame('Nombre conservado', Company::sole()->trade_name);
        $this->assertSame($hash, $admin->fresh()->password);
        $this->assertFalse($admin->fresh()->hasPermission('products.deactivate'));
        $this->assertSame(0, Product::count());
    }

    public function test_database_cache_shares_login_attempts_between_limiter_instances(): void
    {
        $first = new RateLimiter(Cache::store('database'));
        $second = new RateLimiter(Cache::store('database'));
        $first->hit('cloud-deploy-limit', 60);
        $this->assertSame(1, $second->attempts('cloud-deploy-limit'));
        $second->clear('cloud-deploy-limit');
        $this->assertSame(0, $first->attempts('cloud-deploy-limit'));
    }

    public function test_sample_command_requires_explicit_consent_and_never_runs_from_the_default_seeder(): void
    {
        $admin = $this->administrator();
        $this->artisan('sicovi:cargar-ejemplo', ['--admin' => $admin->username])->assertFailed();
        $this->artisan('db:seed', ['--force' => true])->assertSuccessful();
        $this->assertSame(0, Product::count());
        $this->expectException(RuntimeException::class);
        app(CloudDemoSeeder::class)->run();
    }

    public function test_sample_data_uses_business_services_and_rerunning_changes_nothing(): void
    {
        $admin = $this->administrator();
        $company = Company::sole()->getAttributes();
        $this->artisan('sicovi:cargar-ejemplo', ['--admin' => $admin->username, '--confirmar' => true])->assertSuccessful();
        $this->assertSame(4, Product::count());
        $this->assertSame(2, DB::table('suppliers')->count());
        $this->assertSame(3, DB::table('customers')->count());
        $this->assertSame(4, DB::table('purchases')->count());
        $this->assertSame(5, DB::table('sales')->count());
        $this->assertSame(3, DB::table('inventory_adjustments')->count());
        $this->assertDatabaseHas('sales', ['state' => 'voided']);
        $this->assertDatabaseHas('sales', ['state' => 'cancelled']);
        $this->assertDatabaseHas('purchases', ['state' => 'voided']);
        $balances = DB::table('products')->join('inventory_balances', 'products.id', '=', 'inventory_balances.product_id')->pluck('quantity', 'sku')->all();
        $this->assertSame('44.500', $balances['DEMO-ARROZ-KG']);
        $this->assertSame('53.000', $balances['DEMO-CAFE-250G']);
        $this->assertSame('29.000', $balances['DEMO-DETERGENTE-LT']);
        $this->assertSame('53.000', $balances['DEMO-CUADERNO-100']);
        $this->assertSame(0, app(DeploymentPreflight::class)->verifyApplication()['unbalanced']);
        $this->assertSame($company, Company::sole()->getAttributes());
        $this->assertSame(1, User::count());
        $before = [];
        foreach (DeploymentPreflight::TABLES as $table) {
            $before[$table] = DB::table($table)->get()->toJson();
        }
        $this->artisan('sicovi:cargar-ejemplo', ['--admin' => $admin->username, '--confirmar' => true])->assertSuccessful();
        foreach ($before as $table => $values) {
            $this->assertSame($values, DB::table($table)->get()->toJson(), $table);
        }
    }

    public function test_loading_samples_refuses_existing_data_without_modifying_it(): void
    {
        $admin = $this->administrator();
        $this->seed(DemoSeeder::class);
        $before = Product::orderBy('id')->get()->toJson();
        $events = DB::table('audit_events')->count();
        $this->artisan('sicovi:cargar-ejemplo', ['--admin' => $admin->username, '--confirmar' => true])->assertFailed();
        $this->assertSame($before, Product::orderBy('id')->get()->toJson());
        $this->assertSame(0, DB::table('suppliers')->count());
        $this->assertSame($events, DB::table('audit_events')->count());
    }

    public function test_sample_audit_failure_rolls_back_the_whole_batch(): void
    {
        $admin = $this->administrator();
        $before = DB::table('audit_events')->count();
        $audit = $this->createMock(AuditService::class);
        $audit->expects($this->once())->method('record')->willThrowException(new RuntimeException('Controlled failure.'));
        $this->app->instance(AuditService::class, $audit);
        $this->artisan('sicovi:cargar-ejemplo', ['--admin' => $admin->username, '--confirmar' => true])->assertFailed();
        $this->assertSame(0, Product::count());
        $this->assertSame(0, DB::table('categories')->count());
        $this->assertSame($before, DB::table('audit_events')->count());
    }

    public function test_sample_loader_rejects_demo_or_underprivileged_administrators(): void
    {
        $admin = $this->administrator();
        $admin->forceFill(['is_demo' => true])->save();
        $this->artisan('sicovi:cargar-ejemplo', ['--admin' => $admin->username, '--confirmar' => true])->assertFailed();
        $admin->forceFill(['is_demo' => false])->save();
        $admin->role->permissions()->detach(Permission::where('code', 'purchases.confirm')->sole());
        $this->artisan('sicovi:cargar-ejemplo', ['--admin' => $admin->username, '--confirmar' => true])->assertFailed();
        $this->assertSame(0, Product::count());
    }
}
