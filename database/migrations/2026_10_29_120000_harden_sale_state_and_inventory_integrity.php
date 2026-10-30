<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sicovi.sales_state_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF OLD.state = 'draft' AND NEW.state = 'voided' THEN
        RAISE EXCEPTION 'Una venta debe confirmarse antes de anularse.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state = 'voided' AND NEW.state <> 'voided' THEN
        RAISE EXCEPTION 'Una venta anulada no puede volver a otro estado.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state = 'confirmed' AND NEW.state = 'draft' THEN
        RAISE EXCEPTION 'Una venta confirmada no puede volver a borrador.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state <> 'draft' AND (
        NEW.code IS DISTINCT FROM OLD.code
        OR NEW.customer_id IS DISTINCT FROM OLD.customer_id
        OR NEW.warehouse_id IS DISTINCT FROM OLD.warehouse_id
        OR NEW.document_date IS DISTINCT FROM OLD.document_date
        OR NEW.total IS DISTINCT FROM OLD.total
        OR NEW.created_by IS DISTINCT FROM OLD.created_by
        OR NEW.confirmed_by IS DISTINCT FROM OLD.confirmed_by
        OR NEW.confirmed_at IS DISTINCT FROM OLD.confirmed_at
        OR NEW.confirmation_idempotency_key IS DISTINCT FROM OLD.confirmation_idempotency_key
    ) THEN
        RAISE EXCEPTION 'El contenido de una venta confirmada o anulada no se modifica.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state = 'voided' AND (
        NEW.voided_by IS DISTINCT FROM OLD.voided_by
        OR NEW.voided_at IS DISTINCT FROM OLD.voided_at
        OR NEW.void_reason IS DISTINCT FROM OLD.void_reason
    ) THEN
        RAISE EXCEPTION 'Los datos de anulación no se modifican.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$fn$;

CREATE UNIQUE INDEX IF NOT EXISTS inventory_movements_sale_item_unique
    ON sicovi.inventory_movements (sale_item_id)
    WHERE sale_item_id IS NOT NULL AND reversal_of_id IS NULL;

CREATE OR REPLACE FUNCTION sicovi.inventory_movements_sale_quantity_guard()
RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE expected_quantity numeric(14,3);
BEGIN
    IF NEW.sale_item_id IS NULL THEN RETURN NEW; END IF;
    SELECT -i.quantity INTO expected_quantity
      FROM sicovi.sale_items i JOIN sicovi.sales s ON s.id = i.sale_id
     WHERE i.id = NEW.sale_item_id AND s.state IN ('confirmed', 'voided');
    IF NOT FOUND THEN
        RAISE EXCEPTION 'La línea de venta no está confirmada.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.reversal_of_id IS NULL AND (NEW.movement_type <> 'sale' OR NEW.quantity <> expected_quantity) THEN
        RAISE EXCEPTION 'La salida debe corresponder a la cantidad exacta de su línea de venta.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$fn$;

CREATE TRIGGER inventory_movements_sale_quantity_guard
    BEFORE INSERT ON sicovi.inventory_movements
    FOR EACH ROW EXECUTE FUNCTION sicovi.inventory_movements_sale_quantity_guard();

CREATE OR REPLACE FUNCTION sicovi.sale_inventory_integrity() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF NEW.state NOT IN ('confirmed', 'voided') THEN RETURN NULL; END IF;
    IF NOT EXISTS (SELECT 1 FROM sicovi.sale_items WHERE sale_id = NEW.id) THEN
        RAISE EXCEPTION 'Una venta confirmada debe tener al menos una línea.' USING ERRCODE = 'check_violation';
    END IF;
    IF EXISTS (
        SELECT 1 FROM sicovi.sale_items i
         WHERE i.sale_id = NEW.id
           AND NOT EXISTS (
               SELECT 1 FROM sicovi.inventory_movements m
                WHERE m.sale_item_id = i.id AND m.reversal_of_id IS NULL
                  AND m.movement_type = 'sale' AND m.product_id = i.product_id
                  AND m.warehouse_id = NEW.warehouse_id AND m.quantity = -i.quantity
           )
    ) THEN
        RAISE EXCEPTION 'Cada línea confirmada debe tener una salida exacta de inventario.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.state = 'voided' AND EXISTS (
        SELECT 1 FROM sicovi.sale_items i
          JOIN sicovi.inventory_movements m ON m.sale_item_id = i.id AND m.reversal_of_id IS NULL
         WHERE i.sale_id = NEW.id
           AND NOT EXISTS (SELECT 1 FROM sicovi.inventory_movements r WHERE r.reversal_of_id = m.id)
    ) THEN
        RAISE EXCEPTION 'Una venta anulada requiere revertir cada salida de inventario.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NULL;
END
$fn$;

CREATE CONSTRAINT TRIGGER sales_inventory_integrity
    AFTER UPDATE OF state ON sicovi.sales
    DEFERRABLE INITIALLY DEFERRED
    FOR EACH ROW WHEN (OLD.state IS DISTINCT FROM NEW.state)
    EXECUTE FUNCTION sicovi.sale_inventory_integrity();

CREATE OR REPLACE FUNCTION sicovi.inventory_movements_reversal_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE original sicovi.inventory_movements%ROWTYPE;
BEGIN
    IF NEW.reversal_of_id IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO original FROM sicovi.inventory_movements WHERE id = NEW.reversal_of_id;
    IF NOT FOUND THEN RETURN NEW; END IF;
    IF original.reversal_of_id IS NOT NULL THEN
        RAISE EXCEPTION 'Una reversión no se revierte.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.product_id <> original.product_id OR NEW.warehouse_id <> original.warehouse_id THEN
        RAISE EXCEPTION 'La reversión debe corresponder al mismo producto y bodega del movimiento original.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.quantity <> -original.quantity THEN
        RAISE EXCEPTION 'La reversión debe compensar exactamente la cantidad del movimiento original.' USING ERRCODE = 'check_violation';
    END IF;
    IF NEW.purchase_item_id IS DISTINCT FROM original.purchase_item_id
       OR NEW.sale_item_id IS DISTINCT FROM original.sale_item_id
       OR NEW.adjustment_item_id IS DISTINCT FROM original.adjustment_item_id THEN
        RAISE EXCEPTION 'La reversión debe conservar la línea de origen del movimiento original.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$fn$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migración correctiva protegida: requiere reversión manual revisada.');
    }
};
