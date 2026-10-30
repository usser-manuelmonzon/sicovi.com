<?php

/** Spawned only by run_sale_confirmation_race.php in the isolated test DB. */

use App\Models\Sale;
use App\Models\User;
use App\Services\AuditService;
use App\Services\SaleService;
use App\Support\TestDatabaseGuard;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

if (PHP_SAPI !== 'cli' || realpath($_SERVER['SCRIPT_FILENAME'] ?? '') !== __FILE__ || getenv('APP_ENV') !== 'testing') {
    fwrite(STDERR, "Sale race worker blocked.\n");
    exit(2);
}

require dirname(__DIR__, 2).'/vendor/autoload.php';

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

    [$script, $actorId, $saleId, $runDirectory, $nonce, $number] = array_pad($argv, 6, null);
    if (count($argv) !== 6 || ! ctype_digit((string) $actorId) || ! ctype_digit((string) $saleId)
        || ! in_array((string) $number, ['1', '2'], true)
        || ! preg_match('/\A[a-f0-9]{32}\z/', (string) $nonce)) {
        throw new RuntimeException('invalid_worker_arguments');
    }
    $expectedDirectory = $app->storagePath('runtime/sale-race-'.$nonce);
    if (! is_string($runDirectory) || realpath($runDirectory) !== realpath($expectedDirectory)) {
        throw new RuntimeException('invalid_barrier_directory');
    }
    $manifest = json_decode(file_get_contents($runDirectory.'/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    if (($manifest['nonce'] ?? null) !== $nonce || (int) ($manifest['actor_id'] ?? 0) !== (int) $actorId
        || (int) ($manifest['sale_id'] ?? 0) !== (int) $saleId || ($manifest['deadline'] ?? 0) < time()) {
        throw new RuntimeException('invalid_barrier_manifest');
    }

    DB::statement("SET lock_timeout = '15s'");
    DB::statement("SET statement_timeout = '25s'");
    $pid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $ready = $runDirectory.'/worker-'.$number.'.ready';
    file_put_contents($ready.'.tmp', json_encode(['backend_pid' => $pid], JSON_THROW_ON_ERROR), LOCK_EX);
    rename($ready.'.tmp', $ready);
    while (! is_file($runDirectory.'/start')) {
        if (time() >= $manifest['deadline']) {
            throw new RuntimeException('barrier_timeout');
        }
        usleep(10000);
    }

    app()->instance(AuditService::class, new class extends AuditService
    {
        public function record(?User $actor, string $action, Model $entity, array $before = [], array $after = [], ?string $reason = null): void
        {
            if ($action === 'sales.confirmed') {
                DB::select('SELECT pg_sleep(1)');
            }
            parent::record($actor, $action, $entity, $before, $after, $reason);
        }
    });

    app(SaleService::class)->confirmSale(
        Sale::query()->findOrFail((int) $saleId),
        User::query()->findOrFail((int) $actorId),
        'race-'.$nonce,
    );
    echo json_encode(['result' => 'confirmed_or_idempotent', 'backend_pid' => $pid], JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $exception) {
    fwrite(STDERR, json_encode(['result' => 'worker_failed', 'error_type' => $exception::class], JSON_THROW_ON_ERROR).PHP_EOL);
    exit(1);
}
