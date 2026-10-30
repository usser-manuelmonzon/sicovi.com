<?php

/**
 * Real PostgreSQL race: two independent workers confirm the same sale with
 * the same idempotency key. Run only against the guarded sicovi_test DB.
 * Fixtures are retained; the script never truncates or deletes data.
 */

use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\InventoryService;
use App\Services\SaleService;
use App\Support\TestDatabaseGuard;
use Database\Seeders\SystemSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__ || getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "Sale race blocked: use CLI with APP_ENV=testing.\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

$workers = [];
$stage = 'bootstrap';
try {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    if ($app->configurationIsCached()) {
        throw new RuntimeException('cached_configuration');
    }
    $app->loadEnvironmentFrom('.env.testing');
    $app->make(Kernel::class)->bootstrap();
    if (! $app->environment('testing') || config('database.default') !== 'pgsql') {
        throw new RuntimeException('invalid_testing_environment');
    }
    app(TestDatabaseGuard::class)->assertSafe(DB::connection());
    app(SystemSeeder::class)->run();

    $role = Role::where('code', 'admin')->where('active', true)->firstOrFail();
    if (! $role->permissions()->where('code', 'sales.confirm')->exists()
        || ! $role->permissions()->where('code', 'inventory.adjust')->exists()) {
        throw new RuntimeException('test_role_missing_required_permissions');
    }

    $stage = 'fixture_creation';
    $nonce = bin2hex(random_bytes(16));
    $actor = new User();
    $actor->forceFill([
        'name' => 'Prueba concurrencia ventas '.$nonce,
        'email' => 'sale-race-'.$nonce.'@example.test',
        'password' => bin2hex(random_bytes(24)).'aA1!',
        'role_id' => $role->id,
        'active' => true,
    ])->save();
    $warehouseId = (int) DB::table('warehouses')->value('id');
    $unitId = (int) DB::table('units')->where('code', 'UND')->value('id');
    $categoryId = DB::table('categories')->insertGetId([
        'code' => 'RACE-'.$nonce, 'name' => 'Prueba concurrencia '.$nonce,
    ]);
    $productId = DB::table('products')->insertGetId([
        'sku' => 'RACE-'.$nonce, 'name' => 'Producto última unidad '.$nonce,
        'category_id' => $categoryId, 'unit_id' => $unitId,
        'sale_price' => '1.00', 'reference_cost' => '0.50',
        'minimum_stock' => '0.000', 'active' => true,
    ]);
    DB::table('inventory_balances')->insert([
        'product_id' => $productId, 'warehouse_id' => $warehouseId,
        'quantity' => '0.000',
    ]);
    app(InventoryService::class)->applyAdjustment($actor, [
        'type' => 'opening', 'adjustment_date' => now()->toDateString(),
        'reason' => 'Preparación de prueba concurrente',
        'lines' => [['product_id' => $productId, 'quantity' => '1']],
    ]);
    $sale = app(SaleService::class)->createDraft($actor, [
        'document_date' => now()->toDateString(),
    ], [['product_id' => $productId, 'quantity' => '1']]);

    $runDirectory = $app->storagePath('runtime/sale-race-'.$nonce);
    if (! mkdir($runDirectory, 0700, true) && ! is_dir($runDirectory)) {
        throw new RuntimeException('private_runtime_directory_unavailable');
    }
    $deadline = time() + 40;
    file_put_contents($runDirectory.'/manifest.json', json_encode([
        'nonce' => $nonce, 'actor_id' => $actor->id, 'sale_id' => $sale->id,
        'deadline' => $deadline,
    ], JSON_THROW_ON_ERROR), LOCK_EX);

    $phpCommand = [PHP_BINARY];
    if (is_string($loadedIni = php_ini_loaded_file())) {
        array_push($phpCommand, '-c', $loadedIni);
    }
    $probe = new Process([...$phpCommand, '-r', 'exit(extension_loaded("pdo_pgsql") ? 0 : 3);'], dirname(__DIR__, 2));
    $probe->setTimeout(10)->run();
    if ($probe->getExitCode() === 3) {
        array_push($phpCommand, '-d', 'extension=pdo_pgsql');
    } elseif ($probe->getExitCode() !== 0) {
        throw new RuntimeException('worker_php_runtime_unavailable');
    }

    $stage = 'worker_startup';
    for ($number = 1; $number <= 2; $number++) {
        $worker = new Process([
            ...$phpCommand, __DIR__.'/sale_confirmation_worker.php',
            (string) $actor->id, (string) $sale->id, $runDirectory, $nonce, (string) $number,
        ], dirname(__DIR__, 2), ['APP_ENV' => 'testing']);
        $worker->setTimeout(40);
        $worker->start();
        $workers[$number] = $worker;
    }

    $pids = [];
    do {
        foreach ($workers as $number => $worker) {
            $ready = $runDirectory.'/worker-'.$number.'.ready';
            if (is_file($ready)) {
                $pids[$number] = (int) json_decode(file_get_contents($ready), true, flags: JSON_THROW_ON_ERROR)['backend_pid'];
            } elseif (! $worker->isRunning()) {
                throw new RuntimeException('worker_failed_before_barrier');
            }
        }
        if (time() >= $deadline) {
            throw new RuntimeException('worker_readiness_timeout');
        }
        if (count($pids) !== 2) {
            usleep(10000);
        }
    } while (count($pids) !== 2);
    if (count(array_unique($pids)) !== 2) {
        throw new RuntimeException('workers_require_distinct_postgresql_connections');
    }

    file_put_contents($runDirectory.'/start', 'start', LOCK_EX);
    foreach ($workers as $worker) {
        $worker->wait();
    }

    $outcomes = [];
    foreach ($workers as $worker) {
        $outcomes[] = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        if ($worker->getExitCode() !== 0) {
            throw new RuntimeException('sale_confirmation_worker_failed');
        }
    }
    $state = Sale::query()->findOrFail($sale->id)->state;
    $movementCount = DB::table('inventory_movements')
        ->where('sale_item_id', $sale->items()->value('id'))
        ->whereNull('reversal_of_id')->count();
    $balance = (string) DB::table('inventory_balances')->where('product_id', $productId)->value('quantity');
    if ($state !== 'confirmed' || $movementCount !== 1 || $balance !== '0.000'
        || DB::table('idempotency_records')->where('user_id', $actor->id)
            ->where('action', 'sales.confirm')->where('idempotency_key', 'race-'.$nonce)->count() !== 1) {
        throw new RuntimeException('final_inventory_or_idempotency_assertion_failed');
    }

    echo json_encode([
        'result' => 'passed', 'workers' => $outcomes,
        'distinct_postgres_connections' => count(array_unique($pids)),
        'original_sale_movements' => $movementCount, 'balance' => $balance,
        'fixtures_retained' => true,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    foreach ($workers as $worker) {
        if ($worker->isRunning()) {
            $worker->stop(1);
        }
    }
    fwrite(STDERR, json_encode([
        'result' => 'blocked_or_failed', 'stage' => $stage,
        'error_type' => $exception::class, 'fixtures_retained' => true,
    ], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
