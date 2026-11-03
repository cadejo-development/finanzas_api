<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan rrhh:vincular-empleados-plazas [--dry-run]
 *
 * Corrige empleados activos que no tienen plaza_id asignado.
 * Para cada uno:
 *   1. Busca una plaza activa libre del mismo cargo+departamento.
 *   2. Si no existe, crea una plaza nueva.
 *   3. Asigna plaza_id al empleado.
 *   4. Crea registro en plaza_historial (fecha_inicio = fecha_ingreso del empleado).
 */
class VincularEmpleadosPlazas extends Command
{
    protected $signature = 'rrhh:vincular-empleados-plazas
                            {--dry-run : Muestra los cambios sin aplicarlos}';

    protected $description = 'Vincula empleados activos sin plaza_id a una plaza existente o nueva.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        $empleados = DB::connection('pgsql')->select("
            SELECT e.id, e.cargo_id, e.departamento_id,
                   e.nombres || ' ' || e.apellidos AS nombre,
                   e.fecha_ingreso,
                   c.nombre AS cargo_nombre,
                   d.nombre AS dpto_nombre
            FROM empleados e
            LEFT JOIN cargos      c ON c.id = e.cargo_id
            LEFT JOIN departamentos d ON d.id = e.departamento_id
            WHERE e.activo = true
              AND e.plaza_id IS NULL
              AND e.cargo_id IS NOT NULL
            ORDER BY e.departamento_id, e.cargo_id, e.id
        ");

        if (empty($empleados)) {
            $this->info('No hay empleados activos sin plaza. Todo está en orden.');
            return 0;
        }

        $this->line('Empleados activos sin plaza: ' . count($empleados));

        if ($dryRun) {
            $this->warn('Modo --dry-run: no se realizará ningún cambio.');
        }

        $vinculados   = 0;
        $plazasCreadas = 0;

        DB::connection('pgsql')->beginTransaction();
        try {
            foreach ($empleados as $emp) {
                // Buscar plaza libre del mismo cargo+departamento
                $plaza = DB::connection('pgsql')->selectOne("
                    SELECT p.id
                    FROM plazas p
                    WHERE p.cargo_id = ?
                      AND p.departamento_id IS NOT DISTINCT FROM ?
                      AND p.activo = true
                      AND NOT EXISTS (
                          SELECT 1 FROM empleados e
                          WHERE e.plaza_id = p.id AND e.activo = true
                      )
                    ORDER BY p.id
                    LIMIT 1
                    FOR UPDATE SKIP LOCKED
                ", [$emp->cargo_id, $emp->departamento_id]);

                if ($plaza) {
                    $plazaId = $plaza->id;
                    $accion  = 'vinculado a plaza existente';
                } else {
                    if (!$dryRun) {
                        $plazaId = DB::connection('pgsql')->table('plazas')->insertGetId([
                            'cargo_id'        => $emp->cargo_id,
                            'departamento_id' => $emp->departamento_id,
                            'activo'          => true,
                            'aud_usuario'     => 'rrhh:vincular-empleados-plazas',
                            'created_at'      => now(),
                            'updated_at'      => now(),
                        ]);
                        $plazasCreadas++;
                    } else {
                        $plazaId = '(nueva)';
                    }
                    $accion = 'plaza nueva creada';
                }

                $fechaInicio = $emp->fecha_ingreso ?? now()->toDateString();

                $this->line(sprintf(
                    '  → %s | %s / %s | plaza_id=%s | %s | inicio=%s',
                    $emp->nombre,
                    $emp->cargo_nombre,
                    $emp->dpto_nombre ?? 'sin dpto',
                    $plazaId,
                    $accion,
                    $fechaInicio
                ));

                if (!$dryRun) {
                    DB::connection('pgsql')->table('empleados')
                        ->where('id', $emp->id)
                        ->update(['plaza_id' => $plazaId, 'updated_at' => now()]);

                    // plaza_historial: registrar ocupación desde fecha_ingreso
                    $yaExiste = DB::connection('pgsql')->table('plaza_historial')
                        ->where('plaza_id', $plazaId)
                        ->where('empleado_id', $emp->id)
                        ->whereNull('fecha_fin')
                        ->exists();

                    if (!$yaExiste) {
                        DB::connection('pgsql')->table('plaza_historial')->insert([
                            'plaza_id'       => $plazaId,
                            'empleado_id'    => $emp->id,
                            'motivo_entrada' => 'ingreso',
                            'fecha_inicio'   => $fechaInicio,
                            'fecha_fin'      => null,
                            'motivo_salida'  => null,
                            'notas'          => 'Vinculado retroactivamente por rrhh:vincular-empleados-plazas',
                            'aud_usuario'    => 'sistema',
                            'created_at'     => now(),
                            'updated_at'     => now(),
                        ]);
                    }

                    $vinculados++;
                }
            }

            if ($dryRun) {
                DB::connection('pgsql')->rollBack();
                $this->warn('Rollback (dry-run). Ningún cambio aplicado.');
            } else {
                DB::connection('pgsql')->commit();
                $this->info("Vinculados: {$vinculados} empleado(s). Plazas nuevas: {$plazasCreadas}.");
            }
        } catch (\Throwable $e) {
            DB::connection('pgsql')->rollBack();
            $this->error('Error: ' . $e->getMessage());
            return 1;
        }

        return 0;
    }
}
