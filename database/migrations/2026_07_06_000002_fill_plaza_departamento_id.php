<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Actualiza departamento_id en plazas tomándolo del empleado activo vinculado
        DB::connection('pgsql')->statement("
            UPDATE plazas p
            SET    departamento_id = e.departamento_id
            FROM   empleados e
            WHERE  e.plaza_id = p.id
              AND  e.activo   = true
              AND  e.departamento_id IS NOT NULL
              AND  p.departamento_id IS NULL
        ");
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement("UPDATE plazas SET departamento_id = NULL");
    }
};
