<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Añade el valor 'observado' al check constraint del campo estado
     * en solicitud_pago_aprobaciones.
     *
     * Laravel/PostgreSQL implementa enums como varchar + CHECK constraint.
     * Para agregar un valor hay que eliminar y recrear el constraint.
     */
    public function up(): void
    {
        // Only proceed if the table exists and 'observado' is not yet allowed
        if (!Schema::connection('pagos')->hasTable('solicitud_pago_aprobaciones')) {
            return;
        }

        DB::connection('pagos')->statement('
            ALTER TABLE solicitud_pago_aprobaciones
            DROP CONSTRAINT IF EXISTS solicitud_pago_aprobaciones_estado_check
        ');

        DB::connection('pagos')->statement("
            ALTER TABLE solicitud_pago_aprobaciones
            ADD CONSTRAINT solicitud_pago_aprobaciones_estado_check
            CHECK (estado IN ('pendiente','aprobado','rechazado','cancelado','observado'))
        ");
    }

    public function down(): void
    {
        if (!Schema::connection('pagos')->hasTable('solicitud_pago_aprobaciones')) {
            return;
        }

        DB::connection('pagos')->statement('
            ALTER TABLE solicitud_pago_aprobaciones
            DROP CONSTRAINT IF EXISTS solicitud_pago_aprobaciones_estado_check
        ');

        DB::connection('pagos')->statement("
            ALTER TABLE solicitud_pago_aprobaciones
            ADD CONSTRAINT solicitud_pago_aprobaciones_estado_check
            CHECK (estado IN ('pendiente','aprobado','rechazado','cancelado'))
        ");
    }
};
