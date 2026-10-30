<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\DeploymentPreflight;
use Database\Seeders\CloudDemoSeeder;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Throwable;

class LoadCloudDemo extends Command
{
    protected $signature = 'sicovi:cargar-ejemplo {--admin= : Usuario administrador real que autoriza las operaciones} {--confirmar : Confirmar explícitamente la carga en una base sin operaciones}';

    protected $description = 'Carga una sola vez un escenario comercial ficticio sin crear cuentas ni sobrescribir datos';

    public function handle(DeploymentPreflight $preflight): int
    {
        if (! $this->option('confirmar') || ! is_string($this->option('admin')) || trim($this->option('admin')) === '') {
            $this->error('Indique --admin=USUARIO_REAL --confirmar. Revise primero el destino y el respaldo.');

            return self::FAILURE;
        }
        try {
            $preflight->inspect();
            $preflight->verifyApplication();
            $actor = User::where('username', strtolower(trim($this->option('admin'))))->first();
            if (! $actor) {
                $this->error('No se encontró el administrador indicado.');

                return self::FAILURE;
            }
            $seeder = app(CloudDemoSeeder::class)->forAdministrator($actor);
            $seeder->run();
            $preflight->verifyApplication();
            $this->info($seeder->alreadyLoaded ? 'El escenario ya estaba cargado; no se modificó ningún dato.' : 'Escenario ficticio cargado: 4 productos, 2 proveedores, 3 clientes, 4 compras, 5 ventas y 3 ajustes.');
            $this->info('Existencias conciliadas. No se crearon usuarios ni se modificaron contraseñas.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception instanceof \RuntimeException && ! $exception instanceof \PDOException && ! $exception instanceof QueryException
                ? $exception->getMessage()
                : 'No se cargaron los ejemplos. Revise roles, permisos, restricciones y logs privados.');

            return self::FAILURE;
        }
    }
}
