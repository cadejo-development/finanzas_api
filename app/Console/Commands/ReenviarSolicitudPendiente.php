<?php

namespace App\Console\Commands;

use App\Mail\RRHH\SolicitudAprobacion;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Vacacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Reenvía el correo de solicitud pendiente con botones Aprobar/Rechazar
 * al jefe asignado en la solicitud.
 *
 * Uso:
 *   php artisan rrhh:reenviar-solicitud permiso 3
 *   php artisan rrhh:reenviar-solicitud permiso 4
 *   php artisan rrhh:reenviar-solicitud vacacion 7
 */
class ReenviarSolicitudPendiente extends Command
{
    protected $signature = 'rrhh:reenviar-solicitud
                            {tipo : Tipo de solicitud: permiso | vacacion}
                            {id   : ID de la solicitud}
                            {--dry-run : Solo muestra lo que haría, sin enviar}';

    protected $description = 'Reenvía el correo de solicitud pendiente con botones Aprobar/Rechazar al jefe asignado';

    private const MODELOS = [
        'permiso'  => Permiso::class,
        'vacacion' => Vacacion::class,
    ];

    public function handle(): int
    {
        $tipo = $this->argument('tipo');
        $id   = (int) $this->argument('id');
        $dry  = (bool) $this->option('dry-run');

        $modelClass = self::MODELOS[$tipo] ?? null;
        if (! $modelClass) {
            $this->error("Tipo no reconocido: {$tipo}. Usa 'permiso' o 'vacacion'.");
            return self::FAILURE;
        }

        /** @var Permiso|Vacacion $solicitud */
        $solicitud = $modelClass::find($id);
        if (! $solicitud) {
            $this->error("No se encontró {$tipo} con id={$id}.");
            return self::FAILURE;
        }

        if ($solicitud->estado !== 'pendiente') {
            $this->warn("La solicitud ya tiene estado '{$solicitud->estado}'. Solo se reenvían solicitudes pendientes.");
            return self::FAILURE;
        }

        // ── Empleado solicitante ──────────────────────────────────────────────
        $empleado = DB::connection('pgsql')
            ->table('empleados')
            ->where('id', $solicitud->empleado_id)
            ->first();

        if (! $empleado) {
            $this->error("Empleado id={$solicitud->empleado_id} no encontrado.");
            return self::FAILURE;
        }

        $empleadoNombre = trim($empleado->nombres . ' ' . $empleado->apellidos);

        // ── Jefe aprobador ────────────────────────────────────────────────────
        $jefe = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.id', $solicitud->jefe_id)
            ->select('e.nombres', 'e.apellidos', 'u.email')
            ->first();

        if (! $jefe) {
            $this->error("Jefe id={$solicitud->jefe_id} no encontrado o no tiene usuario.");
            return self::FAILURE;
        }

        if (! $jefe->email) {
            $this->error("El jefe id={$solicitud->jefe_id} no tiene correo registrado.");
            return self::FAILURE;
        }

        $supervisorNombre = trim($jefe->nombres . ' ' . $jefe->apellidos);

        // ── Detalles del correo ───────────────────────────────────────────────
        $detalles = $this->buildDetalles($solicitud, $tipo);

        // ── URLs firmadas (5 días) ────────────────────────────────────────────
        $aprobarUrl  = URL::temporarySignedRoute('rrhh.email.aprobar',  now()->addDays(5), ['tipo' => $tipo, 'id' => $id]);
        $rechazarUrl = URL::temporarySignedRoute('rrhh.email.rechazar', now()->addDays(5), ['tipo' => $tipo, 'id' => $id]);

        $baseUrl  = rtrim(config('app.frontend_rrhh_url', 'https://talentohumano.cervezacadejo.com'), '/');
        $rutaMap  = ['permiso' => 'permisos', 'vacacion' => 'vacaciones'];
        $linkUrl  = $baseUrl . '/' . ($rutaMap[$tipo] ?? $tipo);

        $tipoLabel = $tipo === 'vacacion' ? 'Vacaciones' : ucfirst($tipo);

        $this->line("Solicitud : <comment>{$tipoLabel} #{$id}</comment>");
        $this->line("Empleado  : <comment>{$empleadoNombre}</comment>");
        $this->line("Aprobador : <comment>{$supervisorNombre} ({$jefe->email})</comment>");
        $this->line("Estado    : <comment>{$solicitud->estado}</comment>");
        $this->newLine();
        $this->line("URL Aprobar  : {$aprobarUrl}");
        $this->line("URL Rechazar : {$rechazarUrl}");
        $this->newLine();

        if ($dry) {
            $this->warn('[DRY-RUN] No se envió ningún correo.');
            return self::SUCCESS;
        }

        // ── Enviar ────────────────────────────────────────────────────────────
        $mailable = new SolicitudAprobacion(
            tipo:             $tipoLabel,
            empleadoNombre:   $empleadoNombre,
            supervisorNombre: $supervisorNombre,
            detalles:         $detalles,
            linkUrl:          $linkUrl,
            aprobarUrl:       $aprobarUrl,
            rechazarUrl:      $rechazarUrl,
        );

        try {
            Mail::to($jefe->email)->send($mailable);

            DB::connection('pgsql')->table('email_logs')->insert([
                'sistema'         => 'rrhh',
                'tipo'            => 'solicitud_aprobacion',
                'destinatario'    => $jefe->email,
                'asunto'          => $mailable->envelope()->subject,
                'estado'          => 'enviado',
                'enviado_por'     => 'artisan:rrhh:reenviar-solicitud',
                'referencia_id'   => $solicitud->empleado_id,
                'referencia_tipo' => 'empleado',
                'created_at'      => now(),
            ]);

            $this->info("Correo enviado a {$jefe->email} con botones Aprobar/Rechazar.");

        } catch (\Throwable $e) {
            DB::connection('pgsql')->table('email_logs')->insert([
                'sistema'         => 'rrhh',
                'tipo'            => 'solicitud_aprobacion',
                'destinatario'    => $jefe->email,
                'asunto'          => $mailable->envelope()->subject,
                'estado'          => 'fallido',
                'error_mensaje'   => $e->getMessage(),
                'enviado_por'     => 'artisan:rrhh:reenviar-solicitud',
                'referencia_id'   => $solicitud->empleado_id,
                'referencia_tipo' => 'empleado',
                'created_at'      => now(),
            ]);

            Log::error('rrhh:reenviar-solicitud: error enviando correo', ['error' => $e->getMessage()]);
            $this->error("Error al enviar: " . $e->getMessage());
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function buildDetalles($solicitud, string $tipo): array
    {
        if ($tipo === 'permiso') {
            $solicitud->load('tipoPermiso');
            return array_filter([
                'Tipo de permiso' => $solicitud->tipoPermiso?->nombre,
                'Fecha'           => optional($solicitud->fecha)->toDateString() ?? $solicitud->fecha,
                'Días'            => $solicitud->dias       ? $solicitud->dias . ' día(s)' : null,
                'Horas'           => $solicitud->horas_solicitadas ? $solicitud->horas_solicitadas . ' hrs' : null,
                'Motivo'          => $solicitud->motivo,
            ]);
        }

        // vacacion
        return array_filter([
            'Fecha inicio'  => optional($solicitud->fecha_inicio)->toDateString() ?? $solicitud->fecha_inicio,
            'Fecha fin'     => optional($solicitud->fecha_fin)->toDateString()    ?? $solicitud->fecha_fin,
            'Días'          => $solicitud->dias ? $solicitud->dias . ' día(s)' : null,
            'Observaciones' => $solicitud->observaciones,
        ]);
    }
}
