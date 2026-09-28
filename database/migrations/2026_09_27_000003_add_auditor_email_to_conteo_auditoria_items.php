<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // 1. Agregar columna auditor_email (vacío = fila base del conteo; email = fila del auditor)
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_auditoria_items
                ADD COLUMN IF NOT EXISTS auditor_email VARCHAR(255) NOT NULL DEFAULT ''
        ");

        // 2. Todas las filas existentes quedan como filas base (auditor_email = '')
        //    Sus datos de secciones_comprobadas se preservan en la fila base pero
        //    el nuevo flujo los ignorará; los auditores deberán re-verificar.

        // 3. Eliminar el unique constraint antiguo (auditoria_id, producto_id)
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_auditoria_items
                DROP CONSTRAINT IF EXISTS conteo_auditoria_items_auditoria_id_producto_id_unique
        ");

        // 4. Nuevo unique constraint incluye auditor_email
        //    '' = fila base; email real = fila del auditor
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_auditoria_items
                ADD CONSTRAINT conteo_auditoria_items_auditoria_producto_auditor_unique
                UNIQUE (auditoria_id, producto_id, auditor_email)
        ");
    }

    public function down(): void
    {
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_auditoria_items
                DROP CONSTRAINT IF EXISTS conteo_auditoria_items_auditoria_producto_auditor_unique
        ");
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_auditoria_items
                ADD CONSTRAINT conteo_auditoria_items_auditoria_id_producto_id_unique
                UNIQUE (auditoria_id, producto_id)
        ");
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_auditoria_items
                DROP COLUMN IF EXISTS auditor_email
        ");
    }
};
