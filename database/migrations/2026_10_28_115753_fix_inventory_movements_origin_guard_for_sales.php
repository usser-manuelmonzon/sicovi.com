<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sicovi.inventory_movements_origin_guard()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
DECLARE
    origin_state text;
    origin_warehouse bigint;
    origin_product bigint;
BEGIN
    IF NEW.purchase_item_id IS NOT NULL THEN

        SELECT p.state, p.warehouse_id, i.product_id
        INTO origin_state, origin_warehouse, origin_product
        FROM sicovi.purchase_items i
        JOIN sicovi.purchases p ON p.id = i.purchase_id
        WHERE i.id = NEW.purchase_item_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'No existe la línea de compra de origen.'
                USING ERRCODE = 'check_violation';
        END IF;

        IF NEW.reversal_of_id IS NULL
           AND origin_state NOT IN ('confirmed', 'voided') THEN
            RAISE EXCEPTION
                'Solo una compra confirmada genera movimientos de entrada.'
                USING ERRCODE = 'check_violation';
        END IF;

    ELSIF NEW.sale_item_id IS NOT NULL THEN

        SELECT s.state, s.warehouse_id, i.product_id
        INTO origin_state, origin_warehouse, origin_product
        FROM sicovi.sale_items i
        JOIN sicovi.sales s ON s.id = i.sale_id
        WHERE i.id = NEW.sale_item_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'No existe la línea de venta de origen.'
                USING ERRCODE = 'check_violation';
        END IF;

        IF NEW.reversal_of_id IS NULL
           AND origin_state NOT IN ('confirmed', 'voided') THEN
            RAISE EXCEPTION
                'Solo una venta confirmada genera salidas de inventario.'
                USING ERRCODE = 'check_violation';
        END IF;

    ELSIF NEW.adjustment_item_id IS NOT NULL THEN

        SELECT a.state, a.warehouse_id, i.product_id
        INTO origin_state, origin_warehouse, origin_product
        FROM sicovi.inventory_adjustment_items i
        JOIN sicovi.inventory_adjustments a ON a.id = i.adjustment_id
        WHERE i.id = NEW.adjustment_item_id;

        IF NOT FOUND THEN
            RAISE EXCEPTION 'No existe la línea de ajuste de origen.'
                USING ERRCODE = 'check_violation';
        END IF;

    ELSE
        RAISE EXCEPTION 'El movimiento no tiene una línea de origen válida.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF NEW.reversal_of_id IS NOT NULL
       AND origin_state <> 'voided' THEN
        RAISE EXCEPTION
            'Solo se revierte el movimiento de un documento anulado.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF NEW.warehouse_id <> origin_warehouse
       OR NEW.product_id <> origin_product THEN
        RAISE EXCEPTION
            'El movimiento no coincide con el producto o la bodega de su línea de origen.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NULL;
END
$function$;
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Esta corrección requiere una reversión manual revisada.'
        );
    }
};