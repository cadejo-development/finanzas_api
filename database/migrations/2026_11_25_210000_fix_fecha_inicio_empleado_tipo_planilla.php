<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // La migración _200000 pobló empleado_tipo_planilla con created_at = now() (2026-11-25).
        // La migración _200002 backfilleó fecha_inicio = created_at::date → todos quedaron en 2026-11-25.
        // Esto rompía el filtro fecha_inicio <= fechaFin para planillas anteriores a esa fecha.
        // Fix: usar la fecha_ingreso real del empleado como fecha_inicio del tipo planilla.
        DB::statement("
            UPDATE empleado_tipo_planilla etp
            SET fecha_inicio = e.fecha_ingreso, updated_at = NOW()
            FROM empleados e
            WHERE etp.empleado_id = e.id
              AND etp.fecha_inicio = '2026-11-25'
              AND e.fecha_ingreso IS NOT NULL
        ");
    }

    public function down(): void
    {
        // No hay rollback seguro — no podemos saber qué tenían antes.
    }
};
