
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(
                'SICOVI requiere PostgreSQL.'
            );
        }

        if (! DB::connection()->pretending()) {
            $schemaResult = DB::selectOne(
                'SELECT current_schema() AS name'
            );

            if (
                $schemaResult === null
                || $schemaResult->name !== 'sicovi'
            ) {
                throw new RuntimeException(
                    'El esquema activo debe ser sicovi.'
                );
            }
        }

        foreach (['users', 'warehouses', 'products', 'units'] as $required) {
            if (! Schema::hasTable($required)) {
                throw new RuntimeException(
                    "Falta la tabla requerida: {$required}."
                );
            }
        }

        DB::statement(
            'CREATE SEQUENCE customer_code_seq AS bigint START 1 MINVALUE 1 MAXVALUE 999999 NO CYCLE'
        );

        DB::statement(
            'CREATE SEQUENCE sale_code_seq AS bigint START 1 MINVALUE 1 MAXVALUE 999999 NO CYCLE'
        );

        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->string('name', 180);
            $table->string('tax_id', 30)->nullable();
            $table->string('phone', 30)->nullable();
            $table->string('email', 254)->nullable();
            $table->text('address')->nullable();
            $table->boolean('active')->default(true);
            $table->timestampsTz();

            $table->index('name');
            $table->index(['active', 'name']);
        });

        DB::statement(
            "ALTER TABLE customers ALTER COLUMN code SET DEFAULT ('CLI-' || lpad(nextval('customer_code_seq')::text, 6, '0'))"
        );

        DB::statement(
            'ALTER SEQUENCE customer_code_seq OWNED BY customers.code'
        );

        DB::statement(
            'CREATE UNIQUE INDEX customers_code_unique ON customers (code)'
        );

        DB::statement(
            "CREATE UNIQUE INDEX customers_tax_id_normalized_unique
             ON customers (lower(regexp_replace(btrim(tax_id), '[\\s-]+', '', 'g')))
             WHERE tax_id IS NOT NULL"
        );

        DB::statement(
            'ALTER TABLE customers ADD CONSTRAINT customers_name_not_blank CHECK (length(btrim(name)) > 0)'
        );

        DB::statement(
            'ALTER TABLE customers ADD CONSTRAINT customers_code_not_blank CHECK (length(btrim(code)) > 0)'
        );

        foreach (['tax_id', 'phone', 'email'] as $column) {
            DB::statement(
                "ALTER TABLE customers ADD CONSTRAINT customers_{$column}_not_blank CHECK ({$column} IS NULL OR length(btrim({$column})) > 0)"
            );
        }

        Schema::create('sales', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30);
            $table->foreignId('customer_id')
                ->nullable()
                ->constrained('customers')
                ->restrictOnDelete();

            $table->foreignId('warehouse_id')
                ->constrained()
                ->restrictOnDelete();

            $table->date('document_date');
            $table->string('state', 20)->default('draft');
            $table->unsignedInteger('version')->default(0);
            $table->decimal('total', 16, 2)->default('0');

            $table->foreignId('created_by')
                ->constrained('users')
                ->restrictOnDelete();

            $table->foreignId('confirmed_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('confirmed_at')->nullable();
            $table->string('confirmation_idempotency_key', 80)->nullable();

            $table->foreignId('voided_by')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('voided_at')->nullable();
            $table->text('void_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->index(['state', 'document_date']);
            $table->index(['customer_id', 'document_date']);
            $table->index('created_by');
        });

        DB::statement(
            "ALTER TABLE sales ALTER COLUMN code SET DEFAULT ('VEN-' || lpad(nextval('sale_code_seq')::text, 6, '0'))"
        );

        DB::statement(
            'ALTER SEQUENCE sale_code_seq OWNED BY sales.code'
        );

        DB::statement(
            'CREATE UNIQUE INDEX sales_code_unique ON sales (code)'
        );

        DB::statement(
            'CREATE UNIQUE INDEX sales_confirmation_key_unique ON sales (confirmed_by, confirmation_idempotency_key) WHERE confirmation_idempotency_key IS NOT NULL'
        );

        DB::statement(
            "ALTER TABLE sales ADD CONSTRAINT sales_state_check CHECK (state IN ('draft', 'confirmed', 'voided'))"
        );

        DB::statement(
            'ALTER TABLE sales ADD CONSTRAINT sales_version_check CHECK (version >= 0)'
        );

        DB::statement(
            'ALTER TABLE sales ADD CONSTRAINT sales_total_check CHECK (total >= 0)'
        );

        DB::statement(
            'ALTER TABLE sales ADD CONSTRAINT sales_code_not_blank CHECK (length(btrim(code)) > 0)'
        );

        DB::statement(
            'ALTER TABLE sales ADD CONSTRAINT sales_key_not_blank CHECK (confirmation_idempotency_key IS NULL OR length(btrim(confirmation_idempotency_key)) > 0)'
        );

        DB::statement(<<<'SQL'
ALTER TABLE sales ADD CONSTRAINT sales_state_consistency_check CHECK (
    (
        state = 'draft'
        AND confirmed_at IS NULL
        AND confirmed_by IS NULL
        AND confirmation_idempotency_key IS NULL
        AND voided_at IS NULL
        AND voided_by IS NULL
        AND void_reason IS NULL
    )
    OR (
        state = 'confirmed'
        AND confirmed_at IS NOT NULL
        AND confirmed_by IS NOT NULL
        AND voided_at IS NULL
        AND voided_by IS NULL
        AND void_reason IS NULL
    )
    OR (
        state = 'voided'
        AND confirmed_at IS NOT NULL
        AND confirmed_by IS NOT NULL
        AND voided_at IS NOT NULL
        AND voided_by IS NOT NULL
        AND void_reason IS NOT NULL
        AND length(btrim(void_reason)) > 0
    )
)
SQL);

        Schema::create('sale_items', function (Blueprint $table) {
            $table->id();

            $table->foreignId('sale_id')
                ->constrained('sales')
                ->restrictOnDelete();

            $table->foreignId('product_id')
                ->constrained()
                ->restrictOnDelete();

            $table->foreignId('unit_id')
                ->constrained()
                ->restrictOnDelete();

            $table->string('product_sku', 50);
            $table->string('product_name', 180);
            $table->decimal('quantity', 14, 3);
            $table->decimal('unit_price', 14, 2);
            $table->decimal('line_total', 16, 2);
            $table->timestampsTz();

            $table->unique(['sale_id', 'product_id']);
            $table->index('product_id');
        });

        DB::statement(
            'ALTER TABLE sale_items ADD CONSTRAINT sale_items_quantity_check CHECK (quantity > 0)'
        );

        DB::statement(
            'ALTER TABLE sale_items ADD CONSTRAINT sale_items_unit_price_check CHECK (unit_price >= 0)'
        );

        DB::statement(
            'ALTER TABLE sale_items ADD CONSTRAINT sale_items_line_total_check CHECK (line_total = round(quantity * unit_price, 2))'
        );

        DB::statement(
            'ALTER TABLE sale_items ADD CONSTRAINT sale_items_snapshot_not_blank CHECK (length(btrim(product_sku)) > 0 AND length(btrim(product_name)) > 0)'
        );

        // Evita transiciones inválidas y modificaciones de ventas confirmadas.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_state_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF OLD.state = 'voided' AND NEW.state <> 'voided' THEN
        RAISE EXCEPTION 'Una venta anulada no puede volver a otro estado.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF OLD.state = 'confirmed' AND NEW.state = 'draft' THEN
        RAISE EXCEPTION 'Una venta confirmada no puede volver a borrador.'
            USING ERRCODE = 'check_violation';
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
        RAISE EXCEPTION 'El contenido de una venta confirmada o anulada no se modifica.'
            USING ERRCODE = 'check_violation';
    END IF;

    IF OLD.state = 'voided' AND (
        NEW.voided_by IS DISTINCT FROM OLD.voided_by
        OR NEW.voided_at IS DISTINCT FROM OLD.voided_at
        OR NEW.void_reason IS DISTINCT FROM OLD.void_reason
    ) THEN
        RAISE EXCEPTION 'Los datos de anulación no se modifican.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END
$fn$
SQL);

        DB::statement(
            'CREATE TRIGGER sales_state_guard BEFORE UPDATE ON sales FOR EACH ROW EXECUTE FUNCTION sales_state_guard()'
        );


        // Toda venta debe crearse como borrador.
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_insert_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF NEW.state <> 'draft' THEN
        RAISE EXCEPTION 'Toda venta debe crearse como borrador.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN NEW;
END
$fn$
SQL);

        DB::statement(
            'CREATE TRIGGER sales_insert_guard BEFORE INSERT ON sales FOR EACH ROW EXECUTE FUNCTION sales_insert_guard()'
        );


        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sales_no_delete_confirmed() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF OLD.state <> 'draft' THEN
        RAISE EXCEPTION 'No se eliminan ventas confirmadas ni anuladas.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN OLD;
END
$fn$
SQL);

        DB::statement(
            'CREATE TRIGGER sales_no_delete_confirmed BEFORE DELETE ON sales FOR EACH ROW EXECUTE FUNCTION sales_no_delete_confirmed()'
        );

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sale_items_draft_only() RETURNS trigger LANGUAGE plpgsql AS $fn$
DECLARE
    parent_state text;
    parent_id bigint;
BEGIN
    IF TG_OP = 'UPDATE' AND NEW.sale_id IS DISTINCT FROM OLD.sale_id THEN
        RAISE EXCEPTION 'Una línea no puede cambiar de venta.'
            USING ERRCODE = 'check_violation';
    END IF;

    parent_id := CASE WHEN TG_OP = 'DELETE' THEN OLD.sale_id ELSE NEW.sale_id END;

    SELECT state INTO parent_state
      FROM sales
     WHERE id = parent_id
     FOR SHARE;

    IF FOUND AND parent_state <> 'draft' THEN
        RAISE EXCEPTION 'Las líneas solo se modifican mientras la venta está en borrador.'
            USING ERRCODE = 'check_violation';
    END IF;

    RETURN CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END;
END
$fn$
SQL);

        DB::statement(
            'CREATE TRIGGER sale_items_draft_only BEFORE INSERT OR UPDATE OR DELETE ON sale_items FOR EACH ROW EXECUTE FUNCTION sale_items_draft_only()'
        );

    }

    public function down(): void
    {
        throw new RuntimeException(
            'MigraciÃ³n protegida: no se permite eliminar ventas ni clientes mediante rollback automÃ¡tico.'
        );
    }
};
