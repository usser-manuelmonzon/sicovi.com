<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DeploymentPreflight
{
    public const LOCK = 74126900;

    public const TABLES = [
        'companies', 'branches', 'warehouses', 'users', 'roles', 'permissions',
        'role_permissions', 'user_permissions', 'sessions', 'cache', 'cache_locks',
        'categories', 'subcategories', 'brands', 'units', 'products',
        'suppliers', 'purchases', 'purchase_items', 'customers', 'sales', 'sale_items',
        'inventory_balances', 'inventory_movements', 'inventory_adjustments',
        'inventory_adjustment_items', 'idempotency_records', 'audit_events',
    ];

    /** Read-only checks. No schema, permissions or collations are changed. */
    public function inspect(): array
    {
        $connection = DB::connection();
        if ($connection->getDriverName() !== 'pgsql' || $connection->getConfig('search_path') !== 'sicovi') {
            throw new RuntimeException('Se requiere PostgreSQL con DB_SCHEMA=sicovi.');
        }
        if (app()->environment('production')) {
            if (! in_array($connection->getConfig('sslmode'), ['require', 'verify-ca', 'verify-full'], true)) {
                throw new RuntimeException('Producción requiere una conexión que exija TLS.');
            }
            if ((string) $connection->getConfig('port') === '6543') {
                throw new RuntimeException('Use conexión directa o Session Pooler; el modo transacción no conserva search_path ni el bloqueo de despliegue.');
            }
        }
        $identity = $connection->selectOne("SELECT current_database() AS database, current_schema() AS schema, current_setting('server_version_num')::int AS version, current_setting('server_encoding') AS encoding");
        if ($identity->version < 170000 || $identity->encoding !== 'UTF8') {
            throw new RuntimeException('Las migraciones requieren PostgreSQL 17 o posterior con codificación UTF8. No se aplicó ningún cambio.');
        }
        $collation = $connection->selectOne("SELECT to_regcollation('pg_catalog.pg_c_utf8') IS NOT NULL AS available");
        if (! $collation->available) {
            throw new RuntimeException('Falta pg_catalog.pg_c_utf8. Cambie a un servidor compatible; no sustituya la collation.');
        }
        if ($identity->schema !== 'sicovi') {
            throw new RuntimeException('Prepare primero el esquema sicovi con el SQL documentado.');
        }
        $privileges = $connection->selectOne("SELECT has_schema_privilege(current_user, 'sicovi', 'USAGE') AS can_use, has_schema_privilege(current_user, 'sicovi', 'CREATE') AS can_create, has_language_privilege(current_user, 'plpgsql', 'USAGE') AS can_function");
        if (! $privileges->can_use || ! $privileges->can_create || ! $privileges->can_function) {
            throw new RuntimeException('El rol de migración necesita USAGE/CREATE en sicovi y USAGE en plpgsql.');
        }
        $foreignOwners = $connection->selectOne("SELECT count(*) AS total FROM pg_tables WHERE schemaname = 'sicovi' AND NOT pg_has_role(current_user, tableowner, 'USAGE')");
        if ((int) $foreignOwners->total > 0) {
            throw new RuntimeException('Hay tablas de sicovi cuyo propietario no puede administrar este rol; revise los permisos sin transferirlos automáticamente.');
        }

        return ['database' => $identity->database, 'schema' => $identity->schema, 'version' => $identity->version, 'encoding' => $identity->encoding];
    }

    public function verifyApplication(): array
    {
        foreach (self::TABLES as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Falta la tabla {$table}; complete las migraciones antes de habilitar el servicio.");
            }
        }
        $unbalanced = DB::selectOne('SELECT count(*) AS total FROM inventory_balances b FULL OUTER JOIN (SELECT product_id, warehouse_id, sum(quantity) AS quantity FROM inventory_movements GROUP BY product_id, warehouse_id) m USING (product_id, warehouse_id) WHERE b.id IS NULL OR b.quantity < 0 OR b.quantity <> coalesce(m.quantity, 0)');
        if ((int) $unbalanced->total !== 0) {
            throw new RuntimeException('La conciliación de existencias no coincide con los movimientos. Detenga la entrega y revise los datos.');
        }

        return ['tables' => count(self::TABLES), 'unbalanced' => 0];
    }
}
