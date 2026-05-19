<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        // Reemplazar índice único (empleado_id, fecha) por (empleado_id, fecha, tipo)
        // para permitir turno partido: apertura + cierre el mismo día.
        DB::connection('pgsql')->statement(
            'ALTER TABLE horarios_empleado DROP CONSTRAINT IF EXISTS horarios_emp_fecha_unique'
        );
        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX horarios_emp_fecha_tipo_unique
             ON horarios_empleado (empleado_id, fecha, tipo)'
        );
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement(
            'DROP INDEX IF EXISTS horarios_emp_fecha_tipo_unique'
        );
        DB::connection('pgsql')->statement(
            'CREATE UNIQUE INDEX horarios_emp_fecha_unique
             ON horarios_empleado (empleado_id, fecha)'
        );
    }
};
