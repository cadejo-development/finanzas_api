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
 *  - Falta 1 día para el vencimiento → a Admins RRHH y al Jefe/Gerente directo
 *  - El período ya venció y no tiene evaluación registrada → a Admins RRHH
 *  - Ingresos pendientes de confirmación del primer día → a Admins RRHH
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

        // ── 1. Alerta 1 día antes → Admins RRHH + Jefe/Gerente ──────────────
        $manana   = now()->addDay()->toDateString();
        $periodos1 = PeriodoPrueba::where('estado', 'en_prueba')
            ->where('fecha_fin_estimada', $manana)
            ->where('alerta_1_enviada', false)
            ->get();

        foreach ($periodos1 as $p) {
            $this->line("  [1d] Empleado #{$p->empleado_id} — vence {$p->fecha_fin_estimada}");
            if (!$dryRun) {
                $this->enviarAlertaVencimiento($p);
                $p->update(['alerta_1_enviada' => true]);
            }
            $total++;
        }

        // ── 2. Sin evaluación al vencer ──────────────────────────────────────
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

        // ── 3. Ingresos pendientes de confirmación de primer día (más de 1 día) ──
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

    // ── Helpers ──────────────────────────────────────────────────────────────────

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

    /**
     * Obtiene el email del jefe/gerente responsable del empleado.
     * Busca en este orden:
     *  1. Jefes asignados vía empleado_jefaturas por sucursal del empleado
     *  2. Jefes asignados vía empleado_jefaturas por departamento del empleado
     *  3. El responsable_id del mismo período de prueba (user que lo registró)
     */
    private function emailsJefePorEmpleado(PeriodoPrueba $periodo): array
    {
        $empleado = DB::connection('pgsql')
            ->table('empleados')
            ->where('id', $periodo->empleado_id)
            ->select('sucursal_id', 'departamento_id')
            ->first();

        if (!$empleado) return [];

        // Usuarios con jefatura sobre la sucursal o departamento
        $query = DB::connection('pgsql')
            ->table('empleado_jefaturas as ej')
            ->join('users as u', 'u.id', '=', 'ej.user_id')
            ->whereNotNull('u.email')
            ->where(function ($q) use ($empleado) {
                if ($empleado->sucursal_id) {
                    $q->where('ej.sucursal_id', $empleado->sucursal_id);
                }
                if ($empleado->departamento_id) {
                    $q->orWhere('ej.departamento_id', $empleado->departamento_id);
                }
            })
            ->pluck('u.email')
            ->unique()
            ->all();

        // Fallback: responsable_id del período
        if (empty($query) && $periodo->responsable_id) {
            $email = DB::connection('pgsql')
                ->table('users')
                ->where('id', $periodo->responsable_id)
                ->value('email');
            if ($email) $query[] = $email;
        }

        return $query;
    }

    private function frontendUrl(string $ruta): string
    {
        $base = rtrim(config('app.frontend_rrhh_url', env('FRONTEND_RRHH_URL', '')), '/');
        return $base ? "{$base}/{$ruta}" : $ruta;
    }

    private function enviarAlertaVencimiento(PeriodoPrueba $periodo): void
    {
        try {
            $ingreso  = $periodo->ingreso;
            $nombre   = $ingreso?->empleado_nombre ?? "Empleado #{$periodo->empleado_id}";
            $detalles = array_filter([
                'Cargo'          => $ingreso?->cargo_nombre,
                'Sucursal'       => $ingreso?->sucursal_nombre,
                'Inicio periodo' => $periodo->fecha_inicio?->toDateString(),
                'Fin estimado'   => $periodo->fecha_fin_estimada?->toDateString(),
                'Acción'         => 'El período vence mañana. Ingresa al sistema para registrar la evaluación y tomar la decisión de contratación.',
            ]);
            $linkUrl = $this->frontendUrl("ingresos-personal?ver={$ingreso?->id}");

            $emailsAdmin  = $this->adminsRrhhEmails();
            $emailsJefe   = $this->emailsJefePorEmpleado($periodo);
            $destinatarios = array_unique(array_merge($emailsAdmin, $emailsJefe));

            foreach ($destinatarios as $email) {
                Mail::to($email)->send(new AccionPersonalNotificacion(
                    'Periodo de Prueba — Vence Mañana',
                    $nombre,
                    'Recursos Humanos',
                    $detalles,
                    $linkUrl
                ));
            }

            Log::info('rrhh:alertas-periodo-prueba [1d]', [
                'empleado_id'   => $periodo->empleado_id,
                'periodo_id'    => $periodo->id,
                'destinatarios' => $destinatarios,
            ]);
        } catch (\Throwable $e) {
            Log::error('rrhh:alertas-periodo-prueba error [1d]', [
                'error' => $e->getMessage(), 'periodo_id' => $periodo->id,
            ]);
        }
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
            Log::error('rrhh:alertas-periodo-prueba error confirmacion', [
                'error' => $e->getMessage(), 'ingreso_id' => $ingreso->id,
            ]);
        }
    }
}
