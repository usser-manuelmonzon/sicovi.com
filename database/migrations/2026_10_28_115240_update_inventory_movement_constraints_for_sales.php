<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            ALTER TABLE sicovi.inventory_movements
            DROP CONSTRAINT inventory_movements_single_origin_check
        ');

        DB::statement('
            ALTER TABLE sicovi.inventory_movements
            ADD CONSTRAINT inventory_movements_single_origin_check
            CHECK (
                num_nonnulls(
                    purchase_item_id,
                    adjustment_item_id,
                    sale_item_id
                ) = 1
            )
        ');

        DB::statement('
            ALTER TABLE sicovi.inventory_movements
            DROP CONSTRAINT inventory_movements_type_check
        ');

        DB::statement("
            ALTER TABLE sicovi.inventory_movements
            ADD CONSTRAINT inventory_movements_type_check
            CHECK (
                movement_type IN (
                    'purchase',
                    'opening',
                    'extraordinary_entry',
                    'count_adjustment',
                    'sale',
                    'reversal'
                )
            )
        ");

        DB::statement('
            ALTER TABLE sicovi.inventory_movements
            DROP CONSTRAINT inventory_movements_type_origin_check
        ');

        DB::statement("
            ALTER TABLE sicovi.inventory_movements
            ADD CONSTRAINT inventory_movements_type_origin_check
            CHECK (
                (
                    movement_type = 'purchase'
                    AND purchase_item_id IS NOT NULL
                    AND adjustment_item_id IS NULL
                    AND sale_item_id IS NULL
                    AND reversal_of_id IS NULL
                    AND quantity > 0
                )
                OR
                (
                    movement_type IN ('opening', 'extraordinary_entry')
                    AND adjustment_item_id IS NOT NULL
                    AND purchase_item_id IS NULL
                    AND sale_item_id IS NULL
                    AND reversal_of_id IS NULL
                    AND quantity > 0
                )
                OR
                (
                    movement_type = 'count_adjustment'
                    AND adjustment_item_id IS NOT NULL
                    AND purchase_item_id IS NULL
                    AND sale_item_id IS NULL
                    AND reversal_of_id IS NULL
                )
                OR
                (
                    movement_type = 'sale'
                    AND sale_item_id IS NOT NULL
                    AND purchase_item_id IS NULL
                    AND adjustment_item_id IS NULL
                    AND reversal_of_id IS NULL
                    AND quantity < 0
                )
                OR
                (
                    movement_type = 'reversal'
                    AND reversal_of_id IS NOT NULL
                )
            )
        ");
    }

    public function down(): void
    {
        throw new RuntimeException(
            'Esta migracion correctiva requiere una reversión manual revisada.'
        );
    }
};