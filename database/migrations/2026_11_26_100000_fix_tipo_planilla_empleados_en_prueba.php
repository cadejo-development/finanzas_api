<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Para empleados actualmente en período de prueba:
     *   - Cerrar salarios (fecha_fin = fecha_ingreso - 1 día)
     *   - Asignar honorarios (fecha_inicio = fecha_ingreso, fecha_fin = fecha_fin_estimada)
     *
     * Los empleados aprobados ya deberían estar en salarios; esta migración solo
     * toca los que están con estado='en_prueba'.
     */
    public function up(): void
    {
        $salId = DB::connection('pgsql')->table('tipo_planilla')->where('slug', 'salarios')->value('id');
        $honId = DB::connection('pgsql')->table('tipo_planilla')->where('slug', 'honorarios')->value('id');

        if (!$salId || !$honId) return;

        // Leer todos los en prueba desde rrhh
        $enPrueba = DB::connection('rrhh')
            ->table('periodos_prueba')
            ->where('estado', 'en_prueba')
            ->get(['empleado_id', 'fecha_inicio', 'fecha_fin_estimada']);

        if ($enPrueba->isEmpty()) return;

        $empIds = $enPrueba->pluck('empleado_id')->unique()->values()->all();

        // Obtener fecha_ingreso real desde empleados (pgsql)
        $empleados = DB::connection('pgsql')
            ->table('empleados')
            ->whereIn('id', $empIds)
            ->pluck('fecha_ingreso', 'id');

        foreach ($enPrueba as $p) {
            $eid            = $p->empleado_id;
            $fechaIngreso   = $empleados[$eid] ?? $p->fecha_inicio;
            $fechaFinPrueba = $p->fecha_fin_estimada;

            if (!$fechaIngreso || !$fechaFinPrueba) continue;

            // Cerrar salarios: fecha_fin = día antes del ingreso
            $diaAntesIngreso = \Carbon\Carbon::parse($fechaIngreso)->subDay()->toDateString();
            DB::connection('pgsql')->table('empleado_tipo_planilla')
                ->where('empleado_id', $eid)
                ->where('tipo_planilla_id', $salId)
                ->update(['fecha_fin' => $diaAntesIngreso, 'updated_at' => now()]);

            // Upsert honorarios
            $existing = DB::connection('pgsql')->table('empleado_tipo_planilla')
                ->where('empleado_id', $eid)
                ->where('tipo_planilla_id', $honId)
                ->first();

            if ($existing) {
                DB::connection('pgsql')->table('empleado_tipo_planilla')
                    ->where('id', $existing->id)
                    ->update([
                        'fecha_inicio' => $fechaIngreso,
                        'fecha_fin'    => $fechaFinPrueba,
                        'updated_at'   => now(),
                    ]);
            } else {
                DB::connection('pgsql')->table('empleado_tipo_planilla')->insert([
                    'empleado_id'      => $eid,
                    'tipo_planilla_id' => $honId,
                    'fecha_inicio'     => $fechaIngreso,
                    'fecha_fin'        => $fechaFinPrueba,
                    'created_at'       => now(),
                    'updated_at'       => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        // No hay rollback seguro; restaurar manualmente si es necesario.
    }
};
