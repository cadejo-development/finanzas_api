<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Mail\RRHH\VeredictoSolicitud;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Vacacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SolicitudEmailController extends Controller
{
    private const MODELOS = [
        'permiso'  => Permiso::class,
        'vacacion' => Vacacion::class,
    ];

    public function aprobar(Request $request, string $tipo, int $id)
    {
        return $this->procesarAccion($request, $tipo, $id, 'aprobado');
    }

    public function rechazar(Request $request, string $tipo, int $id)
    {
        return $this->procesarAccion($request, $tipo, $id, 'rechazado');
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

        if ($solicitud->estado !== 'pendiente') {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => $solicitud->estado,
                'tipo'    => $tipo,
                'mensaje' => "Esta solicitud ya fue procesada anteriormente (estado: {$solicitud->estado}).",
            ]);
        }

        $solicitud->update(['estado' => $nuevoEstado]);

        // Notificar al empleado solicitante
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

    private function notificarEmpleado($solicitud, string $tipo, string $estado): void
    {
        try {
            // Empleado solicitante
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

            // Supervisor que procesó
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

            $detalles = $this->buildDetalles($solicitud, $tipo);

            $mailable = new VeredictoSolicitud(
                tipo:             $tipoLabel,
                empleadoNombre:   $empleadoNombre,
                supervisorNombre: $supervisorNombre,
                estado:           $estado,
                detalles:         $detalles,
                linkUrl:          $linkUrl,
            );

            Mail::to($empleadoEmail)->send($mailable);

        } catch (\Throwable $e) {
            Log::warning('RRHH: Error enviando correo veredicto al empleado', [
                'error'       => $e->getMessage(),
                'solicitud_id' => $solicitud->id,
                'tipo'        => $tipo,
            ]);
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

        // vacacion
        return array_filter([
            'Fecha inicio'  => $solicitud->fecha_inicio,
            'Fecha fin'     => $solicitud->fecha_fin,
            'Días'          => $solicitud->dias ? $solicitud->dias . ' día(s)' : null,
            'Observaciones' => $solicitud->observaciones,
        ]);
    }
}
