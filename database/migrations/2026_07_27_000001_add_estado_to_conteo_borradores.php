<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar columnas en PostgreSQL directamente (compras connection)
        DB::connection('compras')->statement("
            ALTER TABLE conteo_borradores
              ADD COLUMN IF NOT EXISTS estado VARCHAR(20) NOT NULL DEFAULT 'borrador',
              ADD COLUMN IF NOT EXISTS aplicado_en TIMESTAMP NULL
        ");

        // Índice para filtrar rápido por estado
        DB::connection('compras')->statement("
            CREATE INDEX IF NOT EXISTS idx_conteo_borradores_estado
            ON conteo_borradores (sucursal_id, estado)
        ");
    }

    public function down(): void
    {
        DB::connection('compras')->statement("
            ALTER TABLE conteo_borradores
              DROP COLUMN IF EXISTS estado,
              DROP COLUMN IF EXISTS aplicado_en
        ");
    }
};
