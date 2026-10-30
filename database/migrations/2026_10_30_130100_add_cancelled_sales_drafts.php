<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales', function ($table): void {
            $table->foreignId('cancelled_by')->nullable()->after('void_reason')->constrained('users')->restrictOnDelete();
            $table->timestampTz('cancelled_at')->nullable()->after('cancelled_by');
            $table->text('cancel_reason')->nullable()->after('cancelled_at');
        });

        DB::statement('ALTER TABLE sales DROP CONSTRAINT sales_state_check');
        DB::statement("ALTER TABLE sales ADD CONSTRAINT sales_state_check CHECK (state IN ('draft', 'confirmed', 'voided', 'cancelled'))");
        DB::statement('ALTER TABLE sales DROP CONSTRAINT sales_state_consistency_check');
        DB::statement(<<<'SQL'
ALTER TABLE sales ADD CONSTRAINT sales_state_consistency_check CHECK (
    (state = 'draft' AND confirmed_at IS NULL AND confirmed_by IS NULL AND confirmation_idempotency_key IS NULL AND voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancel_reason IS NULL)
    OR (state = 'confirmed' AND confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL AND voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancel_reason IS NULL)
    OR (state = 'voided' AND confirmed_at IS NOT NULL AND confirmed_by IS NOT NULL AND voided_at IS NOT NULL AND voided_by IS NOT NULL AND void_reason IS NOT NULL AND length(btrim(void_reason)) > 0 AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancel_reason IS NULL)
    OR (state = 'cancelled' AND confirmed_at IS NULL AND confirmed_by IS NULL AND confirmation_idempotency_key IS NULL AND voided_at IS NULL AND voided_by IS NULL AND void_reason IS NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancel_reason IS NOT NULL AND length(btrim(cancel_reason)) > 0)
)
SQL);

        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION sicovi.sales_cancelled_state_guard() RETURNS trigger LANGUAGE plpgsql AS $fn$
BEGIN
    IF OLD.state = 'cancelled' AND NEW.state <> 'cancelled' THEN
        RAISE EXCEPTION 'Un borrador cancelado no puede volver a otro estado.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state = 'cancelled' AND (
        NEW.cancelled_by IS DISTINCT FROM OLD.cancelled_by
        OR NEW.cancelled_at IS DISTINCT FROM OLD.cancelled_at
        OR NEW.cancel_reason IS DISTINCT FROM OLD.cancel_reason
        OR NEW.notes IS DISTINCT FROM OLD.notes
    ) THEN
        RAISE EXCEPTION 'Los datos de cancelación no se modifican.' USING ERRCODE = 'check_violation';
    END IF;
    IF OLD.state = 'draft' AND NEW.state = 'cancelled'
       AND (NEW.cancelled_by IS NULL OR NEW.cancelled_at IS NULL OR NEW.cancel_reason IS NULL OR length(btrim(NEW.cancel_reason)) = 0) THEN
        RAISE EXCEPTION 'La cancelación de un borrador requiere actor, fecha y motivo.' USING ERRCODE = 'check_violation';
    END IF;
    RETURN NEW;
END
$fn$;
CREATE TRIGGER sales_cancelled_state_guard BEFORE UPDATE ON sicovi.sales FOR EACH ROW EXECUTE FUNCTION sicovi.sales_cancelled_state_guard();
SQL);
    }

    public function down(): void
    {
        throw new RuntimeException('Migración de cancelación protegida: requiere revisión de los borradores cancelados y sus auditorías.');
    }
};
