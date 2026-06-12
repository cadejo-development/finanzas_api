<?php

namespace App\Console\Commands;

use App\Models\RRHH\PeriodoPrueba;
use App\Models\RRHH\IngresoPersonal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Mail\RRHH\AccionPersonalNotificacion;

/**
 * php artisan rrhh:alertas-periodo-prueba
 *
 * Revisa todos los períodos de prueba activos y envía alertas cuando:
 *  - Faltan 15 días para el vencimiento
 *  - Faltan 7 días para el vencimiento
 *  - El período ya venció y no tiene evaluación registrada
 *  - También detecta ingresos pendientes de confirmación del primer día (No Show)
 *
 * Se programa para correr diariamente a las 07:00.
 */
class AlertasPeriodoPrueba extends Command
{
    protected $signature = 'rrhh:alertas-periodo-prueba
                            {--dry-run : Muestra alertas sin enviar emails}';

    protected $description = 'Envía alertas automáticas de períodos de prueba próximos a vencer.';

    public function handle(): int
    {
        $hoy    = now()->toDateString();
        $dryRun = $this->option('dry-run');
        $total  = 0;

        // ── 1. Alerta 15 días antes ──────────────────────────────────────────
        $en15 = now()->addDays(15)->toDateString();
        $periodos15 = PeriodoPrueba::where('estado', 'en_prueba')
            ->where('fecha_fin_estimada', $en15)
            ->where('alerta_15_enviada', false)
            ->get();

        foreach ($periodos15 as $p) {
            $this->line("  [15d] Empleado #{$p->empleado_id} — vence {$p->fecha_fin_estimada}");
            if (!$dryRun) {
                $this->enviarAlertaAdmins(
                    $p,
                    'Periodo de Prueba — Vence en 15 dias',
                    "El período de prueba del colaborador vence en 15 días. Registra la evaluación a tiempo."
                );
                $p->update(['alerta_15_enviada' => true]);
            }
            $total++;
        }

        // ── 2. Alerta 7 días antes ───────────────────────────────────────────
        $en7 = now()->addDays(7)->toDateString();
        $periodos7 = PeriodoPrueba::where('estado', 'en_prueba')
            ->where('fecha_fin_estimada', $en7)
            ->where('alerta_7_enviada', false)
            ->get();

        foreach ($periodos7 as $p) {
            $this->line("  [7d] Empleado #{$p->empleado_id} — vence {$p->fecha_fin_estimada}");
            if (!$dryRun) {
                $this->enviarAlertaAdmins(
                    $p,
                    'Periodo de Prueba — Vence en 7 dias',
                    "El período de prueba del colaborador vence en 7 días. Registra la evaluación urgente."
                );
                $p->update(['alerta_7_enviada' => true]);
            }
            $total++;
        }

        // ── 3. Sin evaluación al vencer ──────────────────────────────────────
        $vencidos = PeriodoPrueba::where('estado', 'en_prueba')
            ->where('fecha_fin_estimada', '<', $hoy)
            ->where('alerta_sin_eval_enviada', false)
            ->get();

        foreach ($vencidos as $p) {
            $this->line("  [SIN EVAL] Empleado #{$p->empleado_id} — venció {$p->fecha_fin_estimada}");
            if (!$dryRun) {
                $this->enviarAlertaAdmins(
                    $p,
                    'Periodo de Prueba Vencido Sin Evaluacion',
                    "El período de prueba ya venció y no tiene evaluación registrada. Se requiere acción inmediata."
                );
                $p->update(['alerta_sin_eval_enviada' => true]);
            }
            $total++;
        }

        // ── 4. Ingresos pendientes de confirmación de primer día (más de 1 día) ──
        $ayer = now()->subDay()->toDateString();
        $sinConfirmar = IngresoPersonal::where('confirmacion', 'pendiente')
            ->where('fecha_ingreso', '<=', $ayer)
            ->get();

        foreach ($sinConfirmar as $ingreso) {
            $this->line("  [CONFIRMAR] {$ingreso->empleado_nombre} — ingresó {$ingreso->fecha_ingreso}");
            if (!$dryRun) {
                $this->enviarAlertaConfirmacion($ingreso);
            }
            $total++;
        }

        if ($dryRun) {
            $this->warn("Modo --dry-run: {$total} alertas detectadas, no se enviaron emails.");
        } else {
            $this->info("Alertas enviadas: {$total}");
        }

        return 0;
    }

    private function adminsRrhhEmails(): array
    {
        return DB::connection('pgsql')
            ->table('user_roles as ur')
            ->join('roles as r', 'r.id', '=', 'ur.role_id')
            ->join('users as u', 'u.id', '=', 'ur.user_id')
            ->where('r.name', 'rrhh_admin')
            ->whereNotNull('u.email')
            ->pluck('u.email')
            ->unique()
            ->all();
    }

    private function frontendUrl(string $ruta): string
    {
        $base = rtrim(config('app.frontend_rrhh_url', env('FRONTEND_RRHH_URL', '')), '/');
        return $base ? "{$base}/{$ruta}" : $ruta;
    }

    private function enviarAlertaAdmins(PeriodoPrueba $periodo, string $tipo, string $mensaje): void
    {
        try {
            $ingreso  = $periodo->ingreso;
            $nombre   = $ingreso?->empleado_nombre ?? "Empleado #{$periodo->empleado_id}";
            $detalles = array_filter([
                'Alerta'         => $mensaje,
                'Cargo'          => $ingreso?->cargo_nombre,
                'Sucursal'       => $ingreso?->sucursal_nombre,
                'Inicio periodo' => $periodo->fecha_inicio?->toDateString(),
                'Fin estimado'   => $periodo->fecha_fin_estimada?->toDateString(),
            ]);
            $linkUrl = $this->frontendUrl("ingresos-personal?ver={$ingreso?->id}");

            foreach ($this->adminsRrhhEmails() as $email) {
                Mail::to($email)->send(new AccionPersonalNotificacion(
                    $tipo, $nombre, 'Recursos Humanos', $detalles, $linkUrl
                ));
            }

            Log::info("rrhh:alertas-periodo-prueba [{$tipo}]", [
                'empleado_id' => $periodo->empleado_id,
                'periodo_id'  => $periodo->id,
            ]);
        } catch (\Throwable $e) {
            Log::error("rrhh:alertas-periodo-prueba error [{$tipo}]", [
                'error' => $e->getMessage(), 'periodo_id' => $periodo->id,
            ]);
        }
    }

    private function enviarAlertaConfirmacion(IngresoPersonal $ingreso): void
    {
        try {
            $detalles = array_filter([
                'Mensaje'         => 'Confirmación de presentación pendiente. Registra si se presentó, fue No Show o fue reprogramado.',
                'Cargo'           => $ingreso->cargo_nombre,
                'Sucursal'        => $ingreso->sucursal_nombre,
                'Fecha de ingreso'=> $ingreso->fecha_ingreso?->toDateString(),
            ]);
            $linkUrl = $this->frontendUrl("ingresos-personal?ver={$ingreso->id}");

            foreach ($this->adminsRrhhEmails() as $email) {
                Mail::to($email)->send(new AccionPersonalNotificacion(
                    'Confirmacion de Presentacion Pendiente',
                    $ingreso->empleado_nombre,
                    'Recursos Humanos',
                    $detalles,
                    $linkUrl
                ));
            }
        } catch (\Throwable $e) {
            Log::error("rrhh:alertas-periodo-prueba error confirmacion", [
                'error' => $e->getMessage(), 'ingreso_id' => $ingreso->id,
            ]);
        }
    }
}
