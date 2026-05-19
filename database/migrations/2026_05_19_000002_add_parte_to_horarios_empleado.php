<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        // Agregar columna parte (1, 2 ó 3 para jornadas partidas)
        DB::connection('pgsql')->statement(
            'ALTER TABLE horarios_empleado ADD COLUMN IF NOT EXISTS parte smallint NOT NULL DEFAULT 1'
        );

        // Convertir registros apertura/cierre al nuevo esquema
        DB::connection('pgsql')->statement(
            "UPDATE horarios_empleado SET tipo = 'normal', parte = 1 WHERE tipo = 'apertura'"
        );
        DB::connection('pgsql')->statement(
            "UPDATE horarios_empleado SET tipo = 'normal', parte = 2 WHERE tipo = 'cierre'"
        );

        // Reemplazar índice único (empleado_id, fecha, tipo) → (empleado_id, fecha, parte)
        DB::connection('pgsql')->statement(
            'DROP INDEX IF EXISTS horarios_emp_fecha_tipo_unique'
        );
        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX horarios_emp_fecha_parte_unique
             ON horarios_empleado (empleado_id, fecha, parte)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('DROP INDEX IF EXISTS horarios_emp_fecha_parte_unique');
        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX horarios_emp_fecha_tipo_unique
             ON horarios_empleado (empleado_id, fecha, tipo)'
        );
        DB::connection('pgsql')->statement(
            'ALTER TABLE horarios_empleado DROP COLUMN IF EXISTS parte'
        );
    }
};
