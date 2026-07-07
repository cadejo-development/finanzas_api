<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Plazas de jefes de departamento: el empleado aparece como jefe_empleado_id
        // en la tabla departamentos pero su empleado.departamento_id es NULL
        DB::connection('pgsql')->statement("
            UPDATE plazas p
            SET    departamento_id = d.id
            FROM   empleados e
            JOIN   departamentos d ON d.jefe_empleado_id = e.id
            WHERE  e.plaza_id = p.id
              AND  e.activo   = true
              AND  p.departamento_id IS NULL
        ");
    }

    public function down(): void {}
};
