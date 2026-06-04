<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Mail\RRHH\AccionPersonalNotificacion;
use App\Mail\RRHH\NotificacionAlEmpleado;
use App\Mail\RRHH\VeredictoSolicitud;
use App\Models\RRHH\Amonestacion;
use App\Models\RRHH\Desvinculacion;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Vacacion;
use App\Services\RRHH\AmonestacionPdfService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SolicitudEmailController extends Controller
{
    private const MODELOS = [
        'permiso'     => Permiso::class,
        'vacacion'    => Vacacion::class,
        'amonestacion'=> Amonestacion::class,
        'despido'     => Desvinculacion::class,
    ];

    public function aprobar(Request $request, string $tipo, int $id)
    {
        return $this->procesarAccion($request, $tipo, $id, 'aprobado');
    }

    public function rechazar(Request $request, string $tipo, int $id)
    {
        if (! $request->hasValidSignature()) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => 'rechazado',
                'tipo'    => $tipo,
                'mensaje' => 'El enlace ha expirado o no es válido.',
            ]);
        }

        // Para amonestaciones muy graves se requiere ingresar el motivo de rechazo
        if ($tipo === 'amonestacion') {
            $solicitud = Amonestacion::with('tipoFalta')->find($id);
            if ($solicitud && $solicitud->tipoFalta?->gravedad === 'muy_grave' && $solicitud->estado === 'pendiente_rrhh') {
                session(['rechazar_amonestacion_autorizado' => $id]);
                return view('rrhh.rechazar-form', ['id' => $id, 'tipo' => $tipo, 'amonestacion' => $solicitud]);
            }
        }

        return $this->procesarAccion($request, $tipo, $id, 'rechazado');
    }

    public function rechazarConMotivo(Request $request, string $tipo, int $id)
    {
        if ((int) session('rechazar_amonestacion_autorizado') !== $id) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => 'rechazado',
                'tipo'    => $tipo,
                'mensaje' => 'Sesión inválida o expirada. Usa el enlace del correo nuevamente.',
            ]);
        }

        $solicitud = Amonestacion::with('tipoFalta')->find($id);

        if (! $solicitud || $solicitud->estado !== 'pendiente_rrhh') {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => 'rechazado',
                'tipo'    => $tipo,
                'mensaje' => 'Esta solicitud ya fue procesada o no existe.',
            ]);
        }

        $motivo = trim($request->input('motivo', ''));

        $solicitud->update([
            'estado'         => 'rechazado',
            'aprobado_en'    => now(),
            'rechazo_motivo' => $motivo ?: null,
        ]);

        $this->notificarJefeRechazoMuyGrave($solicitud, $motivo);

        session()->forget('rechazar_amonestacion_autorizado');

        return view('rrhh.resultado-solicitud', [
            'exito'   => true,
            'accion'  => 'rechazado',
            'tipo'    => 'Amonestación muy grave',
            'mensaje' => 'La amonestación fue rechazada. Se notificó al jefe responsable.',
        ]);
    }

    private function procesarAccion(Request $request, string $tipo, int $id, string $nuevoEstado)
    {
        if (! $request->hasValidSignature()) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => $nuevoEstado,
                'tipo'    => $tipo,
                'mensaje' => 'El enlace ha expirado o no es válido. Por favor gestiona la solicitud directamente desde el sistema.',
            ]);
        }

        $modelClass = self::MODELOS[$tipo] ?? null;

        if (! $modelClass) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => $nuevoEstado,
                'tipo'    => $tipo,
                'mensaje' => 'Tipo de solicitud no reconocido.',
            ]);
        }

        $solicitud = $modelClass::find($id);

        if (! $solicitud) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => $nuevoEstado,
                'tipo'    => $tipo,
                'mensaje' => 'La solicitud no fue encontrada en el sistema.',
            ]);
        }

        $estadosPendientes = ['pendiente', 'pendiente_gerencia_ops', 'pendiente_rrhh'];
        if (! in_array($solicitud->estado, $estadosPendientes)) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => $solicitud->estado,
                'tipo'    => $tipo,
                'mensaje' => "Esta solicitud ya fue procesada anteriormente (estado: {$solicitud->estado}).",
            ]);
        }

        $solicitud->update([
            'estado'       => $nuevoEstado,
            'aprobado_en'  => now(),
        ]);

        // Para despidos aprobados: inactivar al empleado si la fecha efectiva ya llegó
        if ($tipo === 'despido' && $nuevoEstado === 'aprobado') {
            $this->procesarDespidoAprobado($solicitud);
        }

        // Notificar resultado
        $this->notificarEmpleado($solicitud, $tipo, $nuevoEstado);

        return view('rrhh.resultado-solicitud', [
            'exito'   => true,
            'accion'  => $nuevoEstado,
            'tipo'    => $tipo,
            'mensaje' => $nuevoEstado === 'aprobado'
                ? 'La solicitud fue aprobada exitosamente. Se notificó al empleado.'
                : 'La solicitud fue rechazada. Se notificó al empleado.',
        ]);
    }

    private function procesarDespidoAprobado(Desvinculacion $desvinculacion): void
    {
        try {
            if ($desvinculacion->fecha_efectiva <= now()->toDateString()) {
                DB::connection('pgsql')
                    ->table('empleados')
                    ->where('id', $desvinculacion->empleado_id)
                    ->update(['activo' => false, 'aud_usuario' => 'sistema:desvinculacion:aprobado', 'updated_at' => now()]);
            }
            // Casos futuros los cubre el cron rrhh:inactivar-desvinculados (ya maneja estado='aprobado').
        } catch (\Throwable $e) {
            Log::warning('RRHH: Error inactivando empleado al aprobar despido', [
                'desvinculacion_id' => $desvinculacion->id,
                'error'             => $e->getMessage(),
            ]);
        }
    }

    private function notificarEmpleado($solicitud, string $tipo, string $estado): void
    {
        // Para acciones de jefatura sobre subordinados (amonestacion, despido):
        // - Si aprobado: notificar al empleado afectado
        // - Si rechazado: notificar al jefe que creó el registro (procesado_por_id / jefe_id)
        if (in_array($tipo, ['amonestacion', 'despido'])) {
            $this->notificarVeredictoAccion($solicitud, $tipo, $estado);
            return;
        }

        try {
            // Empleado solicitante (permisos/vacaciones)
            $empleado = DB::connection('pgsql')
                ->table('empleados')
                ->where('id', $solicitud->empleado_id)
                ->first();

            if (! $empleado) return;

            $empleadoEmail = DB::connection('pgsql')
                ->table('users')
                ->where('id', $empleado->user_id)
                ->value('email');

            if (! $empleadoEmail) return;

            $supervisor = DB::connection('pgsql')
                ->table('empleados')
                ->where('id', $solicitud->jefe_id)
                ->first();

            $supervisorNombre = $supervisor
                ? trim($supervisor->nombres . ' ' . $supervisor->apellidos)
                : 'Tu supervisor';

            $empleadoNombre = trim($empleado->nombres . ' ' . $empleado->apellidos);

            $baseUrl  = rtrim(config('app.frontend_rrhh_url', 'https://talentohumano.cervezacadejo.com'), '/');
            $rutaMap  = ['permiso' => 'permisos', 'vacacion' => 'vacaciones'];
            $linkUrl  = $baseUrl . '/' . ($rutaMap[$tipo] ?? $tipo);

            $tipoLabel = ucfirst($tipo === 'vacacion' ? 'Vacaciones' : $tipo);

            $mailable = new VeredictoSolicitud(
                tipo:             $tipoLabel,
                empleadoNombre:   $empleadoNombre,
                supervisorNombre: $supervisorNombre,
                estado:           $estado,
                detalles:         $this->buildDetalles($solicitud, $tipo),
                linkUrl:          $linkUrl,
            );

            Mail::to($empleadoEmail)->send($mailable);

        } catch (\Throwable $e) {
            Log::warning('RRHH: Error enviando correo veredicto al empleado', [
                'error'        => $e->getMessage(),
                'solicitud_id' => $solicitud->id,
                'tipo'         => $tipo,
            ]);
        }
    }

    private function notificarVeredictoAccion($solicitud, string $tipo, string $estado): void
    {
        try {
            $baseUrl  = rtrim(config('app.frontend_rrhh_url', 'https://www.talentohumano.cervezacadejo.com'), '/');
            $linkUrl  = match ($tipo) {
                'amonestacion' => "{$baseUrl}/amonestaciones?ver={$solicitud->id}",
                'despido'      => "{$baseUrl}/desvinculaciones/despidos?ver={$solicitud->id}",
                default        => "{$baseUrl}/{$tipo}",
            };
            $detalles = $this->buildDetalles($solicitud, $tipo);

            if ($tipo === 'amonestacion') {
                $solicitud->loadMissing('tipoFalta');
            }
            $esMuyGrave = $tipo === 'amonestacion' && $solicitud->tipoFalta?->gravedad === 'muy_grave';

            if ($estado === 'aprobado') {
                $empleado = DB::connection('pgsql')->table('empleados')->where('id', $solicitud->empleado_id)->first();
                $empleadoEmail = $empleado
                    ? DB::connection('pgsql')->table('users')->where('id', $empleado->user_id)->value('email')
                    : null;

                if ($empleadoEmail) {
                    $empleadoNombre = trim($empleado->nombres . ' ' . $empleado->apellidos);
                    $tipoLabel      = $tipo === 'amonestacion' ? 'Amonestación' : 'Despido';
                    $mensaje        = $tipo === 'amonestacion'
                        ? 'Se ha registrado una amonestacion aprobada en tu expediente.'
                        : 'Tu desvinculacion ha sido procesada. Contacta a RRHH para los pasos a seguir.';

                    // Generar PDF para amonestaciones
                    $pdfContent = null;
                    $pdfNombre  = null;
                    if ($tipo === 'amonestacion') {
                        try {
                            $pdfContent = AmonestacionPdfService::generar($solicitud)->output();
                            $pdfNombre  = AmonestacionPdfService::nombreArchivo($solicitud);
                        } catch (\Throwable $e) {
                            Log::warning('RRHH: Error generando PDF en aprobación', ['id' => $solicitud->id, 'error' => $e->getMessage()]);
                        }
                    }

                    $mailable = new NotificacionAlEmpleado(
                        tipo:               $tipoLabel,
                        empleadoNombre:     $empleadoNombre,
                        mensaje:            $mensaje,
                        detalles:           $detalles,
                        linkUrl:            $linkUrl . '/mi-expediente',
                        destinatarioNombre: $empleadoNombre,
                        pdfContent:         $pdfContent,
                        pdfNombre:          $pdfNombre,
                    );

                    Mail::to($empleadoEmail)->send($mailable);
                }

                // Si es muy grave aprobada: notificar también al jefe (confirmación)
                if ($esMuyGrave) {
                    $this->notificarJefeAprobacionMuyGrave($solicitud, $detalles, $linkUrl);
                }

            } else {
                // Rechazado (propina/despido): notificar al jefe que creó el registro
                $jefeId = $tipo === 'amonestacion' ? $solicitud->jefe_id : $solicitud->procesado_por_id;
                $jefe   = DB::connection('pgsql')->table('empleados')->where('id', $jefeId)->first();
                $jefeEmail = $jefe
                    ? DB::connection('pgsql')->table('users')->where('id', $jefe->user_id)->value('email')
                    : null;

                if ($jefeEmail) {
                    $empleado   = DB::connection('pgsql')->table('empleados')->where('id', $solicitud->empleado_id)->first();
                    $empNombre  = $empleado ? trim($empleado->nombres . ' ' . $empleado->apellidos) : "Empleado #{$solicitud->empleado_id}";
                    $jefeNombre = trim($jefe->nombres . ' ' . $jefe->apellidos);
                    $tipoLabel  = $tipo === 'amonestacion' ? 'Amonestación' : 'Despido';

                    $mailable = new AccionPersonalNotificacion(
                        tipo:             "{$tipoLabel} — Rechazado por Gerencia de Operaciones",
                        empleadoNombre:   $empNombre,
                        supervisorNombre: $jefeNombre,
                        detalles:         $detalles,
                        linkUrl:          $linkUrl,
                    );

                    Mail::to($jefeEmail)->send($mailable);
                }
            }
        } catch (\Throwable $e) {
            Log::warning('RRHH: Error notificando veredicto de acción', ['tipo' => $tipo, 'error' => $e->getMessage()]);
        }
    }

    private function notificarJefeAprobacionMuyGrave($solicitud, array $detalles, string $linkUrl): void
    {
        $baseUrl = rtrim(config('app.frontend_rrhh_url', 'https://www.talentohumano.cervezacadejo.com'), '/');
        $linkUrl = "{$baseUrl}/amonestaciones?ver={$solicitud->id}";
        try {
            $jefe = DB::connection('pgsql')->table('empleados')->where('id', $solicitud->jefe_id)->first();
            $jefeEmail = $jefe
                ? DB::connection('pgsql')->table('users')->where('id', $jefe->user_id)->value('email')
                : null;
            if (! $jefeEmail) return;

            $empleado = DB::connection('pgsql')->table('empleados')->where('id', $solicitud->empleado_id)->first();
            $empNombre  = $empleado ? trim($empleado->nombres . ' ' . $empleado->apellidos) : "Empleado #{$solicitud->empleado_id}";
            $jefeNombre = trim($jefe->nombres . ' ' . $jefe->apellidos);

            Mail::to($jefeEmail)->send(new AccionPersonalNotificacion(
                tipo:             'Amonestación Muy Grave — Aprobada por RRHH',
                empleadoNombre:   $empNombre,
                supervisorNombre: $jefeNombre,
                detalles:         $detalles,
                linkUrl:          $linkUrl,
            ));
        } catch (\Throwable $e) {
            Log::warning('RRHH: Error notificando jefe de aprobación muy grave', ['error' => $e->getMessage()]);
        }
    }

    private function notificarJefeRechazoMuyGrave($solicitud, string $motivo): void
    {
        try {
            $baseUrl = rtrim(config('app.frontend_rrhh_url', 'https://www.talentohumano.cervezacadejo.com'), '/');
            $linkUrl = "{$baseUrl}/amonestaciones?ver={$solicitud->id}";

            $jefe = DB::connection('pgsql')->table('empleados')->where('id', $solicitud->jefe_id)->first();
            $jefeEmail = $jefe
                ? DB::connection('pgsql')->table('users')->where('id', $jefe->user_id)->value('email')
                : null;
            if (! $jefeEmail) return;

            $empleado  = DB::connection('pgsql')->table('empleados')->where('id', $solicitud->empleado_id)->first();
            $empNombre = $empleado ? trim($empleado->nombres . ' ' . $empleado->apellidos) : "Empleado #{$solicitud->empleado_id}";
            $jefeNombre = trim($jefe->nombres . ' ' . $jefe->apellidos);

            $detalles = $this->buildDetalles($solicitud, 'amonestacion');
            if ($motivo) {
                $detalles['Motivo de rechazo'] = $motivo;
            }

            Mail::to($jefeEmail)->send(new AccionPersonalNotificacion(
                tipo:             'Amonestación Muy Grave — Rechazada por RRHH',
                empleadoNombre:   $empNombre,
                supervisorNombre: $jefeNombre,
                detalles:         $detalles,
                linkUrl:          $linkUrl,
            ));
        } catch (\Throwable $e) {
            Log::warning('RRHH: Error notificando jefe de rechazo muy grave', ['error' => $e->getMessage()]);
        }
    }

    private function buildDetalles($solicitud, string $tipo): array
    {
        if ($tipo === 'permiso') {
            $solicitud->load('tipoPermiso');
            return array_filter([
                'Tipo de permiso' => $solicitud->tipoPermiso?->nombre,
                'Fecha'           => $solicitud->fecha,
                'Días'            => $solicitud->dias ? $solicitud->dias . ' día(s)' : null,
                'Horas'           => $solicitud->horas_solicitadas ? $solicitud->horas_solicitadas . ' hrs' : null,
                'Motivo'          => $solicitud->motivo,
            ]);
        }

        if ($tipo === 'amonestacion') {
            $solicitud->load('tipoFalta');
            return array_filter([
                'Tipo de falta'      => $solicitud->tipoFalta?->nombre,
                'Fecha'              => $solicitud->fecha_amonestacion,
                'Descripción'        => $solicitud->descripcion,
                'Suspensión propina' => $solicitud->aplica_suspension_propina ? 'Sí' : null,
            ]);
        }

        if ($tipo === 'despido') {
            $solicitud->load('motivo');
            return array_filter([
                'Tipo'           => 'Despido',
                'Fecha efectiva' => $solicitud->fecha_efectiva,
                'Motivo'         => $solicitud->motivo?->nombre,
                'Cargo'          => $solicitud->cargo_nombre,
                'Sucursal'       => $solicitud->sucursal_nombre,
            ]);
        }

        // vacacion
        return array_filter([
            'Fecha inicio'  => $solicitud->fecha_inicio,
            'Fecha fin'     => $solicitud->fecha_fin,
            'Días'          => $solicitud->dias ? $solicitud->dias . ' día(s)' : null,
            'Observaciones' => $solicitud->observaciones,
        ]);
    }
}
