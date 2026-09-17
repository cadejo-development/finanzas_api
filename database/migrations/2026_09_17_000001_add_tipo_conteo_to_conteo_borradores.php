<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_borradores
              ADD COLUMN IF NOT EXISTS tipo_conteo VARCHAR(30) NOT NULL DEFAULT 'conteo_fisico';
        ");

        // Borradores cuyo payload incluye la clave 'secciones' son de tipo mensual
        DB::connection('compras')->unprepared("
            UPDATE conteo_borradores
               SET tipo_conteo = 'conteo_mensual'
             WHERE payload::jsonb ? 'secciones';
        ");

        // Reemplazar el unique constraint por uno que incluya tipo_conteo
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_borradores
              DROP CONSTRAINT IF EXISTS conteo_borradores_sucursal_id_aud_usuario_unique;
        ");

        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_borradores
              ADD CONSTRAINT conteo_borradores_sucursal_aud_tipo_unique
              UNIQUE (sucursal_id, aud_usuario, tipo_conteo);
        ");
    }

    public function down(): void
    {
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_borradores
              DROP CONSTRAINT IF EXISTS conteo_borradores_sucursal_aud_tipo_unique;
        ");

        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_borradores
              ADD CONSTRAINT conteo_borradores_sucursal_id_aud_usuario_unique
              UNIQUE (sucursal_id, aud_usuario);
        ");

        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_borradores
              DROP COLUMN IF EXISTS tipo_conteo;
        ");
    }
};
