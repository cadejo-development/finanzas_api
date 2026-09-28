<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Reemplazar unique(sucursal_id, aud_usuario, tipo_conteo)
        // por unique(sucursal_id, aud_usuario, tipo_conteo, fecha_conteo)
        // para que cada mes genere su propio registro de borrador
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_borradores
              DROP CONSTRAINT IF EXISTS conteo_borradores_sucursal_aud_tipo_unique;
        ");

        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_borradores
              ADD CONSTRAINT conteo_borradores_sucursal_aud_tipo_fecha_unique
              UNIQUE (sucursal_id, aud_usuario, tipo_conteo, fecha_conteo);
        ");
    }

    public function down(): void
    {
        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_borradores
              DROP CONSTRAINT IF EXISTS conteo_borradores_sucursal_aud_tipo_fecha_unique;
        ");

        DB::connection('compras')->unprepared("
            ALTER TABLE conteo_borradores
              ADD CONSTRAINT conteo_borradores_sucursal_aud_tipo_unique
              UNIQUE (sucursal_id, aud_usuario, tipo_conteo);
        ");
    }
};
