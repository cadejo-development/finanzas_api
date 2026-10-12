<?php

namespace App\Console\Commands;

use App\Services\RRHH\PlazaHistorialService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * php artisan rrhh:reparar-historial-plazas [--dry-run]
 *
 * Cierra todos los registros de plaza_historial que quedaron abiertos
 * (fecha_fin = null) para empleados que ya están inactivos.
 *
 * Ocurre cuando InactivarEmpleadosDesvinculados procesó la baja antes de
 * que se agregara el call a PlazaHistorialService::liberar().
 */
class RepararHistorialPlazas extends Command
{
    protected $signature = 'rrhh:reparar-historial-plazas
                            {--dry-run : Muestra los registros a reparar sin modificar nada}';

    protected $description = 'Cierra registros huérfanos de plaza_historial para empleados ya desvinculados.';

    public function handle(): int
    {
        $dryRun = $this->option('dry-run');

        // Registros abiertos (fecha_fin = null) cuyo empleado ya no está activo
        $huerfanos = DB::connection('pgsql')->select("
            SELECT
                ph.id            AS historial_id,
                ph.plaza_id,
                ph.empleado_id,
                ph.fecha_inicio,
                e.nombres || ' ' || e.apellidos AS empleado_nombre
            FROM plaza_historial ph
            JOIN empleados e ON e.id = ph.empleado_id
            WHERE ph.fecha_fin IS NULL
              AND e.activo = false
            ORDER BY ph.plaza_id, ph.fecha_inicio
        ");

        if (empty($huerfanos)) {
            $this->info('No hay registros huérfanos. Todo está en orden.');
            return 0;
        }

        $this->line("Registros huérfanos encontrados: " . count($huerfanos));

        // IDs de empleados afectados para buscar sus desvinculaciones en rrhh DB
        $empleadoIds = array_unique(array_column($huerfanos, 'empleado_id'));
        $empleadoIdsStr = implode(',', array_map('intval', $empleadoIds));

        $desvinculaciones = DB::connection('rrhh')->select("
            SELECT empleado_id, tipo, fecha_efectiva
            FROM desvinculaciones
            WHERE empleado_id IN ({$empleadoIdsStr})
            ORDER BY fecha_efectiva DESC
        ");

        // Construir mapa: empleado_id → desvinculación más reciente
        $dvMap = [];
        foreach ($desvinculaciones as $dv) {
            if (!isset($dvMap[$dv->empleado_id])) {
                $dvMap[$dv->empleado_id] = $dv;
            }
        }

        $service   = app(PlazaHistorialService::class);
        $reparados = 0;
        $sinDv     = 0;

        foreach ($huerfanos as $h) {
            $dv = $dvMap[$h->empleado_id] ?? null;

            if ($dv) {
                $fechaFin = Carbon::parse($dv->fecha_efectiva);
                $motivo   = $dv->tipo === 'despido' ? 'despido' : 'renuncia';
            } else {
                // Sin desvinculación registrada — usar fecha actual como fallback
                $fechaFin = now();
                $motivo   = 'renuncia';
                $sinDv++;
            }

            $tag = $dv ? "[{$dv->tipo} {$dv->fecha_efectiva}]" : "[sin_dv → hoy]";
            $this->line("  → {$h->empleado_nombre} | plaza_id={$h->plaza_id} | historial_id={$h->historial_id} | {$tag}");

            if (!$dryRun) {
                $service->liberar($h->plaza_id, $fechaFin, $motivo, 'Reparado por rrhh:reparar-historial-plazas');
                $reparados++;

                Log::channel('daily')->info('rrhh:reparar-historial-plazas', [
                    'historial_id' => $h->historial_id,
                    'plaza_id'     => $h->plaza_id,
                    'empleado_id'  => $h->empleado_id,
                    'empleado'     => $h->empleado_nombre,
                    'fecha_fin'    => $fechaFin->toDateString(),
                    'motivo'       => $motivo,
                    'sin_dv'       => !$dv,
                ]);
            }
        }

        if ($dryRun) {
            $this->warn('Modo --dry-run: no se realizó ningún cambio.');
            if ($sinDv > 0) {
                $this->warn("{$sinDv} registro(s) sin desvinculación encontrada — se usaría la fecha de hoy como fecha_fin.");
            }
        } else {
            $this->info("Reparados: {$reparados} registro(s).");
            if ($sinDv > 0) {
                $this->warn("{$sinDv} registro(s) no tenían desvinculación — se usó la fecha de hoy.");
            }
        }

        return 0;
    }
}
