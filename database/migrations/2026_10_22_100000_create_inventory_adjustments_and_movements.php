<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * YOURGUEN-DB-002 - Movimientos de inventario (Kardex), ajustes y conciliacion saldo/movimientos.
 *
 * Aditiva: no modifica tablas ni migraciones anteriores. Crea inventory_adjustments,
 * inventory_adjustment_items e inventory_movements, mas funciones y triggers que hacen cumplir en
 * PostgreSQL: movimiento inmutable, origen verificable, reversion unica, saldo = suma de movimientos
 * y "confirmar compra = recibirla". Las tablas se crean con SQL explicito (claves compuestas,
 * indices parciales y CHECK) para que el esquema probado sea exactamente el que se aplica.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('SICOVI requiere PostgreSQL y el esquema sicovi previamente preparado.');
        }
        if (DB::selectOne('select current_schema() as name')->name !== 'sicovi') {
            throw new RuntimeException('El esquema activo debe ser sicovi. Revise DB_SCHEMA y la preparación de PostgreSQL.');
        }
        foreach (['users', 'warehouses', 'products', 'units', 'inventory_balances', 'purchases', 'purchase_items'] as $required) {
            if (! Schema::hasTable($required)) {
                throw new RuntimeException("Falta la tabla {$required}: aplique primero las migraciones de Manuel y YOURGUEN-DB-001/001B.");
            }
        }
        // Todo saldo debe nacer de un movimiento. Un saldo previo distinto de cero no tiene movimiento
        // que lo respalde: la migracion se detiene en vez de inventar historia.
        if (DB::table('inventory_balances')->whereRaw('quantity <> 0')->exists()) {
            throw new RuntimeException('Existen saldos distintos de cero sin movimientos. Registre primero su inventario inicial o consulte al autor; no se modificaron datos.');
        }

        DB::unprepared(<<<'SQL'
CREATE SEQUENCE adjustment_code_seq AS bigint START 1 MINVALUE 1 MAXVALUE 999999 NO CYCLE
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE inventory_adjustments (
    id bigserial PRIMARY KEY,
    code varchar(30) COLLATE pg_catalog.pg_c_utf8 NOT NULL DEFAULT ('AJU-' || lpad(nextval('adjustment_code_seq')::text, 6, '0')),
    warehouse_id bigint NOT NULL REFERENCES warehouses (id) ON DELETE RESTRICT,
    type varchar(30) NOT NULL,
    adjustment_date date NOT NULL,
    reason text NOT NULL,
    reference varchar(120) COLLATE pg_catalog.pg_c_utf8 NULL,
    notes text NULL,
    state varchar(20) NOT NULL DEFAULT 'applied',
    created_by bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
    applied_idempotency_key varchar(80) NULL,
    voided_by bigint NULL REFERENCES users (id) ON DELETE RESTRICT,
    voided_at timestamptz NULL,
    void_reason text NULL,
    created_at timestamptz NULL,
    updated_at timestamptz NULL,
    CONSTRAINT inventory_adjustments_code_unique UNIQUE (code),
    CONSTRAINT inventory_adjustments_id_type_unique UNIQUE (id, type),
    CONSTRAINT inventory_adjustments_type_check CHECK (type IN ('opening', 'extraordinary_entry', 'count_adjustment')),
    CONSTRAINT inventory_adjustments_state_check CHECK (state IN ('applied', 'voided')),
    CONSTRAINT inventory_adjustments_code_not_blank CHECK (length(btrim(code)) > 0),
    CONSTRAINT inventory_adjustments_reason_not_blank CHECK (length(btrim(reason)) > 0),
    CONSTRAINT inventory_adjustments_reference_not_blank CHECK (reference IS NULL OR length(btrim(reference)) > 0),
    CONSTRAINT inventory_adjustments_key_not_blank CHECK (applied_idempotency_key IS NULL OR length(btrim(applied_idempotency_key)) > 0),
    CONSTRAINT inventory_adjustments_state_consistency_check CHECK (
        (state = 'applied' AND voided_by IS NULL AND voided_at IS NULL AND void_reason IS NULL)
        OR (state = 'voided' AND voided_by IS NOT NULL AND voided_at IS NOT NULL
            AND void_reason IS NOT NULL AND length(btrim(void_reason)) > 0)
    )
)
SQL);

        DB::unprepared(<<<'SQL'
ALTER SEQUENCE adjustment_code_seq OWNED BY inventory_adjustments.code
SQL);

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX inventory_adjustments_key_unique ON inventory_adjustments (created_by, applied_idempotency_key) WHERE applied_idempotency_key IS NOT NULL
SQL);

        DB::unprepared(<<<'SQL'
CREATE INDEX inventory_adjustments_warehouse_date_index ON inventory_adjustments (warehouse_id, adjustment_date)
SQL);

        DB::unprepared(<<<'SQL'
CREATE INDEX inventory_adjustments_type_date_index ON inventory_adjustments (type, adjustment_date)
SQL);

        DB::unprepared(<<<'SQL'
CREATE INDEX inventory_adjustments_created_by_index ON inventory_adjustments (created_by)
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE inventory_adjustment_items (
    id bigserial PRIMARY KEY,
    adjustment_id bigint NOT NULL,
    adjustment_type varchar(30) NOT NULL,
    product_id bigint NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
    unit_id bigint NOT NULL REFERENCES units (id) ON DELETE RESTRICT,
    product_sku varchar(50) NOT NULL,
    product_name varchar(180) NOT NULL,
    counted_quantity numeric(14, 3) NULL,
    recorded_quantity numeric(14, 3) NULL,
    quantity_delta numeric(14, 3) NOT NULL,
    created_at timestamptz NULL,
    updated_at timestamptz NULL,
    CONSTRAINT inventory_adjustment_items_adjustment_fk FOREIGN KEY (adjustment_id, adjustment_type)
        REFERENCES inventory_adjustments (id, type) ON DELETE RESTRICT,
    CONSTRAINT inventory_adjustment_items_product_unique UNIQUE (adjustment_id, product_id),
    CONSTRAINT inventory_adjustment_items_delta_check CHECK (quantity_delta <> 0),
    CONSTRAINT inventory_adjustment_items_quantities_check CHECK (
        (counted_quantity IS NULL OR counted_quantity >= 0) AND (recorded_quantity IS NULL OR recorded_quantity >= 0)
    ),
    CONSTRAINT inventory_adjustment_items_shape_check CHECK (
        (adjustment_type = 'count_adjustment' AND counted_quantity IS NOT NULL AND recorded_quantity IS NOT NULL
            AND quantity_delta = counted_quantity - recorded_quantity)
        OR (adjustment_type IN ('opening', 'extraordinary_entry') AND counted_quantity IS NULL
            AND recorded_quantity IS NULL AND quantity_delta > 0)
    ),
    CONSTRAINT inventory_adjustment_items_snapshot_not_blank CHECK (length(btrim(product_sku)) > 0 AND length(btrim(product_name)) > 0)
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE INDEX inventory_adjustment_items_product_index ON inventory_adjustment_items (product_id)
SQL);

        DB::unprepared(<<<'SQL'
CREATE TABLE inventory_movements (
    id bigserial PRIMARY KEY,
    product_id bigint NOT NULL REFERENCES products (id) ON DELETE RESTRICT,
    warehouse_id bigint NOT NULL REFERENCES warehouses (id) ON DELETE RESTRICT,
    movement_type varchar(30) NOT NULL,
    quantity numeric(14, 3) NOT NULL,
    balance_before numeric(14, 3) NOT NULL,
    balance_after numeric(14, 3) NOT NULL,
    purchase_item_id bigint NULL REFERENCES purchase_items (id) ON DELETE RESTRICT,
    adjustment_item_id bigint NULL REFERENCES inventory_adjustment_items (id) ON DELETE RESTRICT,
    reversal_of_id bigint NULL REFERENCES inventory_movements (id) ON DELETE RESTRICT,
    actor_id bigint NOT NULL REFERENCES users (id) ON DELETE RESTRICT,
    reason text NULL,
    occurred_at timestamptz NOT NULL DEFAULT now(),
    CONSTRAINT inventory_movements_type_check CHECK (movement_type IN ('purchase', 'opening', 'extraordinary_entry', 'count_adjustment', 'reversal')),
    CONSTRAINT inventory_movements_quantity_check CHECK (quantity <> 0),
    CONSTRAINT inventory_movements_balances_check CHECK (balance_before >= 0 AND balance_after >= 0 AND balance_after = balance_before + quantity),
    CONSTRAINT inventory_movements_single_origin_check CHECK (num_nonnulls(purchase_item_id, adjustment_item_id) = 1),
    CONSTRAINT inventory_movements_type_origin_check CHECK (
        (movement_type = 'purchase' AND purchase_item_id IS NOT NULL AND reversal_of_id IS NULL AND quantity > 0)
        OR (movement_type IN ('opening', 'extraordinary_entry') AND adjustment_item_id IS NOT NULL AND reversal_of_id IS NULL AND quantity > 0)
        OR (movement_type = 'count_adjustment' AND adjustment_item_id IS NOT NULL AND reversal_of_id IS NULL)
        OR (movement_type = 'reversal' AND reversal_of_id IS NOT NULL)
    ),
    CONSTRAINT inventory_movements_reason_check CHECK (
        (movement_type NOT IN ('opening', 'extraordinary_entry', 'count_adjustment', 'reversal') OR (reason IS NOT NULL AND length(btrim(reason)) > 0))
        AND (reason IS NULL OR length(btrim(reason)) > 0)
    )
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX inventory_movements_purchase_item_unique ON inventory_movements (purchase_item_id) WHERE purchase_item_id IS NOT NULL AND reversal_of_id IS NULL
SQL);

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX inventory_movements_adjustment_item_unique ON inventory_movements (adjustment_item_id) WHERE adjustment_item_id IS NOT NULL AND reversal_of_id IS NULL
SQL);

        DB::unprepared(<<<'SQL'
CREATE UNIQUE INDEX inventory_movements_reversal_unique ON inventory_movements (reversal_of_id) WHERE reversal_of_id IS NOT NULL
SQL);

        DB::unprepared(<<<'SQL'
CREATE INDEX inventory_movements_kardex_index ON inventory_movements (product_id, warehouse_id, id)
SQL);

        DB::unprepared(<<<'SQL'
CREATE INDEX inventory_movements_occurred_index ON inventory_movements (occurred_at, id)
SQL);

        DB::unprepared(<<<'SQL'
CREATE INDEX inventory_movements_type_index ON inventory_movements (movement_type, occurred_at)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_movements_immutable() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    RAISE EXCEPTION 'Los movimientos de inventario no se editan ni se borran; se corrigen con una reversión.' USING ERRCODE = 'check_violation';
END
$fn$
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inventory_movements_immutable BEFORE UPDATE OR DELETE ON inventory_movements FOR EACH ROW EXECUTE FUNCTION inventory_movements_immutable()
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_movements_reversal_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE
    original inventory_movements%ROWTYPE;
BEGIN
    IF NEW.reversal_of_id IS NULL THEN
        RETURN NEW;
    END IF;
    SELECT * INTO original FROM inventory_movements WHERE id = NEW.reversal_of_id;
    IF NOT FOUND THEN
        RETURN NEW; -- la clave foránea rechazará el movimiento original inexistente
    END IF;
    IF original.reversal_of_id IS NOT NULL THEN
        RAISE EXCEPTION 'Una reversión no se revierte.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.product_id <> original.product_id OR NEW.warehouse_id <> original.warehouse_id THEN
        RAISE EXCEPTION 'La reversión debe corresponder al mismo producto y bodega del movimiento original.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.quantity <> -original.quantity THEN
        RAISE EXCEPTION 'La reversión debe compensar exactamente la cantidad del movimiento original.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.purchase_item_id IS DISTINCT FROM original.purchase_item_id OR NEW.adjustment_item_id IS DISTINCT FROM original.adjustment_item_id THEN
        RAISE EXCEPTION 'La reversión debe conservar la línea de origen del movimiento original.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$fn$
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inventory_movements_reversal_guard BEFORE INSERT ON inventory_movements FOR EACH ROW EXECUTE FUNCTION inventory_movements_reversal_guard()
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_reconcile() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE
    current_balance numeric(14, 3);
    movement_sum numeric(14, 3);
    last_balance numeric(14, 3);
BEGIN
    SELECT quantity INTO current_balance FROM inventory_balances WHERE product_id = NEW.product_id AND warehouse_id = NEW.warehouse_id;
    IF NOT FOUND THEN
        RAISE EXCEPTION 'No existe saldo para el producto % en la bodega %.', NEW.product_id, NEW.warehouse_id USING ERRCODE = 'check_violation';
    END IF;
    SELECT COALESCE(SUM(quantity), 0) INTO movement_sum FROM inventory_movements WHERE product_id = NEW.product_id AND warehouse_id = NEW.warehouse_id;
    IF current_balance <> movement_sum THEN
        RAISE EXCEPTION 'El saldo (%) no coincide con la suma de movimientos (%) del producto % en la bodega %.', current_balance, movement_sum, NEW.product_id, NEW.warehouse_id USING ERRCODE = 'check_violation';
    END IF;
    SELECT balance_after INTO last_balance FROM inventory_movements WHERE product_id = NEW.product_id AND warehouse_id = NEW.warehouse_id ORDER BY id DESC LIMIT 1;
    IF FOUND AND last_balance <> current_balance THEN
        RAISE EXCEPTION 'El saldo posterior del último movimiento (%) no coincide con el saldo actual (%).', last_balance, current_balance USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END
$fn$
SQL);

        DB::unprepared(<<<'SQL'
CREATE CONSTRAINT TRIGGER inventory_balances_reconcile AFTER INSERT OR UPDATE ON inventory_balances DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_reconcile()
SQL);

        DB::unprepared(<<<'SQL'
CREATE CONSTRAINT TRIGGER inventory_movements_reconcile AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_reconcile()
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_movements_origin_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE
    origin_state text;
    origin_warehouse bigint;
    origin_product bigint;
BEGIN
    IF NEW.purchase_item_id IS NOT NULL THEN
        SELECT p.state, p.warehouse_id, i.product_id INTO origin_state, origin_warehouse, origin_product
          FROM purchase_items i JOIN purchases p ON p.id = i.purchase_id WHERE i.id = NEW.purchase_item_id;
        IF NEW.reversal_of_id IS NULL AND origin_state NOT IN ('confirmed', 'voided') THEN
            RAISE EXCEPTION 'Solo una compra confirmada genera movimientos de entrada.' USING ERRCODE = 'check_violation';
        END IF;
    ELSE
        SELECT a.state, a.warehouse_id, i.product_id INTO origin_state, origin_warehouse, origin_product
          FROM inventory_adjustment_items i JOIN inventory_adjustments a ON a.id = i.adjustment_id WHERE i.id = NEW.adjustment_item_id;
    END IF;
    IF NEW.reversal_of_id IS NOT NULL AND origin_state <> 'voided' THEN
        RAISE EXCEPTION 'Solo se revierte el movimiento de un documento anulado.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.warehouse_id <> origin_warehouse OR NEW.product_id <> origin_product THEN
        RAISE EXCEPTION 'El movimiento no coincide con el producto o la bodega de su línea de origen.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END
$fn$
SQL);

        DB::unprepared(<<<'SQL'
CREATE CONSTRAINT TRIGGER inventory_movements_origin_guard AFTER INSERT ON inventory_movements DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_movements_origin_guard()
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_adjustments_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF TG_OP = 'DELETE' THEN
        RAISE EXCEPTION 'No se eliminan ajustes de inventario; se anulan con reversión.' USING ERRCODE = 'check_violation';
    END IF;
    IF TG_OP = 'INSERT' THEN
        IF NEW.state <> 'applied' THEN
            RAISE EXCEPTION 'Todo ajuste se registra como aplicado.' USING ERRCODE = 'check_violation';
        END IF;
        RETURN NEW;
    END IF;
    IF OLD.state = 'voided' AND NEW.state <> 'voided' THEN
        RAISE EXCEPTION 'Un ajuste anulado no puede volver a otro estado.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.code IS DISTINCT FROM OLD.code OR NEW.warehouse_id IS DISTINCT FROM OLD.warehouse_id
       OR NEW.type IS DISTINCT FROM OLD.type OR NEW.adjustment_date IS DISTINCT FROM OLD.adjustment_date
       OR NEW.reason IS DISTINCT FROM OLD.reason OR NEW.reference IS DISTINCT FROM OLD.reference
       OR NEW.created_by IS DISTINCT FROM OLD.created_by
       OR NEW.applied_idempotency_key IS DISTINCT FROM OLD.applied_idempotency_key THEN
        RAISE EXCEPTION 'El contenido de un ajuste aplicado no se modifica.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state = 'voided' AND (NEW.voided_by IS DISTINCT FROM OLD.voided_by OR NEW.voided_at IS DISTINCT FROM OLD.voided_at
       OR NEW.void_reason IS DISTINCT FROM OLD.void_reason) THEN
        RAISE EXCEPTION 'Los datos de anulación no se modifican.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$fn$
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inventory_adjustments_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_adjustments FOR EACH ROW EXECUTE FUNCTION inventory_adjustments_guard()
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_adjustment_items_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE
    parent_state text;
BEGIN
    IF TG_OP <> 'INSERT' THEN
        RAISE EXCEPTION 'Las líneas de un ajuste no se editan ni se borran.' USING ERRCODE = 'check_violation';
    END IF;
    SELECT state INTO parent_state FROM inventory_adjustments WHERE id = NEW.adjustment_id FOR SHARE;
    IF FOUND AND parent_state <> 'applied' THEN
        RAISE EXCEPTION 'No se agregan líneas a un ajuste anulado.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$fn$
SQL);

        DB::unprepared(<<<'SQL'
CREATE TRIGGER inventory_adjustment_items_guard BEFORE INSERT OR UPDATE OR DELETE ON inventory_adjustment_items FOR EACH ROW EXECUTE FUNCTION inventory_adjustment_items_guard()
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION inventory_adjustment_integrity() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE
    header inventory_adjustments%ROWTYPE;
    header_id bigint;
BEGIN
    IF TG_TABLE_NAME = 'inventory_adjustments' THEN
        header_id := NEW.id;
    ELSE
        header_id := NEW.adjustment_id;
    END IF;
    SELECT * INTO header FROM inventory_adjustments WHERE id = header_id;
    IF NOT FOUND THEN
        RETURN NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM inventory_adjustment_items WHERE adjustment_id = header_id) THEN
        RAISE EXCEPTION 'Un ajuste debe tener al menos una línea.' USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (
        SELECT 1 FROM inventory_adjustment_items i
         WHERE i.adjustment_id = header_id
           AND NOT EXISTS (
               SELECT 1 FROM inventory_movements m
                WHERE m.adjustment_item_id = i.id AND m.reversal_of_id IS NULL
                  AND m.product_id = i.product_id AND m.warehouse_id = header.warehouse_id
                  AND m.quantity = i.quantity_delta
                  AND (header.type <> 'count_adjustment' OR m.balance_before = i.recorded_quantity)
           )
    ) THEN
        RAISE EXCEPTION 'Cada línea del ajuste debe tener su movimiento de inventario exacto.' USING ERRCODE = 'check_violation';
    END IF;
    IF header.state = 'voided' AND EXISTS (
        SELECT 1 FROM inventory_adjustment_items i
          JOIN inventory_movements m ON m.adjustment_item_id = i.id AND m.reversal_of_id IS NULL
         WHERE i.adjustment_id = header_id
           AND NOT EXISTS (SELECT 1 FROM inventory_movements r WHERE r.reversal_of_id = m.id)
    ) THEN
        RAISE EXCEPTION 'Un ajuste anulado debe tener la reversión de cada uno de sus movimientos.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END
$fn$
SQL);

        DB::unprepared(<<<'SQL'
CREATE CONSTRAINT TRIGGER inventory_adjustments_integrity AFTER INSERT OR UPDATE ON inventory_adjustments DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_adjustment_integrity()
SQL);

        DB::unprepared(<<<'SQL'
CREATE CONSTRAINT TRIGGER inventory_adjustment_items_integrity AFTER INSERT ON inventory_adjustment_items DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION inventory_adjustment_integrity()
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION purchase_inventory_integrity() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF NEW.state NOT IN ('confirmed', 'voided') THEN
        RETURN NULL;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM purchase_items WHERE purchase_id = NEW.id) THEN
        RAISE EXCEPTION 'Una compra confirmada debe tener al menos una línea.' USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (
        SELECT 1 FROM purchase_items i
         WHERE i.purchase_id = NEW.id
           AND NOT EXISTS (
               SELECT 1 FROM inventory_movements m
                WHERE m.purchase_item_id = i.id AND m.reversal_of_id IS NULL AND m.movement_type = 'purchase'
                  AND m.product_id = i.product_id AND m.warehouse_id = NEW.warehouse_id AND m.quantity = i.quantity
           )
    ) THEN
        RAISE EXCEPTION 'Confirmar una compra es recibirla: cada línea debe tener su movimiento de entrada exacto.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.state = 'voided' AND EXISTS (
        SELECT 1 FROM purchase_items i
          JOIN inventory_movements m ON m.purchase_item_id = i.id AND m.reversal_of_id IS NULL
         WHERE i.purchase_id = NEW.id
           AND NOT EXISTS (SELECT 1 FROM inventory_movements r WHERE r.reversal_of_id = m.id)
    ) THEN
        RAISE EXCEPTION 'Una compra anulada debe tener la reversión de cada una de sus entradas.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END
$fn$
SQL);

        DB::unprepared(<<<'SQL'
CREATE CONSTRAINT TRIGGER purchases_inventory_integrity AFTER UPDATE OF state ON purchases DEFERRABLE INITIALLY DEFERRED FOR EACH ROW WHEN (OLD.state IS DISTINCT FROM NEW.state) EXECUTE FUNCTION purchase_inventory_integrity()
SQL);
    }

    public function down(): void
    {
        // ATENCION: elimina ajustes y movimientos (el Kardex). Solo para BD descartables o con respaldo verificado.
        DB::statement('DROP TRIGGER IF EXISTS purchases_inventory_integrity ON purchases');
        DB::statement('DROP TRIGGER IF EXISTS inventory_balances_reconcile ON inventory_balances');
        DB::statement('DROP TABLE IF EXISTS inventory_movements');
        DB::statement('DROP TABLE IF EXISTS inventory_adjustment_items');
        DB::statement('DROP TABLE IF EXISTS inventory_adjustments');
        foreach ([
            'purchase_inventory_integrity', 'inventory_adjustment_integrity', 'inventory_adjustment_items_guard',
            'inventory_adjustments_guard', 'inventory_movements_origin_guard', 'inventory_reconcile',
            'inventory_movements_reversal_guard', 'inventory_movements_immutable',
        ] as $function) {
            DB::statement("DROP FUNCTION IF EXISTS {$function}()");
        }
        DB::statement('DROP SEQUENCE IF EXISTS adjustment_code_seq');
    }
};
