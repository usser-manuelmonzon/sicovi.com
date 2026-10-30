<?php

namespace App\Console\Commands;

use App\Support\DeploymentPreflight;
use App\Support\TestDatabaseGuard;
use Database\Seeders\SystemSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

class PrepareDeployment extends Command
{
    protected $signature = 'sicovi:preparar-despliegue {--aplicar : Ejecutar migraciones incrementales y SystemSeeder explícitamente}';

    protected $description = 'Comprueba PostgreSQL y, solo con --aplicar, prepara la instalación sin borrar datos ni cambiar claves';

    public function handle(DeploymentPreflight $preflight): int
    {
        $locked = false;
        try {
            $identity = $preflight->inspect();
            $this->info('PostgreSQL compatible: versión '.$identity['version'].', UTF8, esquema sicovi y collation verificados.');
            if ($this->option('aplicar')) {
                if (app()->environment('testing')) {
                    app(TestDatabaseGuard::class)->assertSafe(DB::connection());
                } elseif (! app()->environment('production')) {
                    $this->error('La aplicación de este procedimiento se reserva a producción o a sicovi_test protegido.');

                    return self::FAILURE;
                }
                $locked = (bool) DB::selectOne('SELECT pg_try_advisory_lock(?) AS acquired', [DeploymentPreflight::LOCK])->acquired;
                if (! $locked) {
                    $this->error('Otro proceso está preparando el despliegue. Espere a que termine.');

                    return self::FAILURE;
                }
                if ($this->call('migrate', ['--force' => true]) !== self::SUCCESS
                    || $this->call('db:seed', ['--class' => SystemSeeder::class, '--force' => true]) !== self::SUCCESS) {
                    return self::FAILURE;
                }
            }
            if (Schema::hasTable('users')) {
                $result = $preflight->verifyApplication();
                $this->info($result['tables'].' tablas verificadas; existencias conciliadas.');
            } else {
                $this->line('Base preparada para inicializar. Ejecute este comando con --aplicar después de revisar el respaldo/destino.');
            }
            $this->info('No se crearon usuarios ni datos de ejemplo y no se modificó APP_KEY.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            // Never echo PDO messages: they may embed connection details.
            $this->error($exception instanceof \RuntimeException && ! $exception instanceof \PDOException && ! $exception instanceof QueryException
                ? $exception->getMessage()
                : 'Falló la comprobación o preparación. Revise conexión, migraciones y permisos en los logs privados.');

            return self::FAILURE;
        } finally {
            if ($locked) {
                try {
                    DB::select('SELECT pg_advisory_unlock(?)', [DeploymentPreflight::LOCK]);
                } catch (Throwable) {
                    $this->warn('No se confirmó la liberación del bloqueo; cierre esta conexión antes de reintentar.');
                }
            }
        }
    }
}
