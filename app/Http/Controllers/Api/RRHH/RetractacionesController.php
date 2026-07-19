<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Mail\RRHH\NotificacionAlEmpleado;
use App\Models\RRHH\Desvinculacion;
use App\Models\RRHH\SolicitudRetractacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class RetractacionesController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    // Email del gerente general: nunca recibe notificaciones de RRHH
    private const EXCLUIR_EMAIL = 'david@cervezacadejo.com';

    // ── GET /api/rrhh/retractaciones ────────────────────────────────────────
    public function index(): JsonResponse
    {
        $query = SolicitudRetractacion::orderByDesc('id');

        if (!$this->esAdminRrhh() && !$this->esGerenciaOps()) {
            $jefe = $this->getJefeEmpleado();
            $query->where('solicitado_por_empleado_id', $jefe->id);
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    // ── POST /api/rrhh/retractaciones ──────────────────────────────────────
    // Gerente solicita retractación de una renuncia de su equipo
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $validated = $request->validate([
                'desvinculacion_id' => 'required|integer',
                'justificacion'     => 'required|string|min:10|max:1000',
            ]);

            $desvinculacion = Desvinculacion::findOrFail($validated['desvinculacion_id']);

            if ($desvinculacion->tipo !== 'renuncia') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden retractar renuncias.'], 422);
            }
            if ($desvinculacion->estado === 'retractada') {
                return response()->json(['success' => false, 'message' => 'Esta renuncia ya fue retractada.'], 422);
            }

            if (!$this->esAdminRrhh() && !$this->esGerenciaOps()) {
                $jefe = $this->getJefeEmpleado();
                if ($desvinculacion->procesado_por_id !== $jefe->id) {
                    return response()->json(['success' => false, 'message' => 'No tienes permiso sobre esta renuncia.'], 403);
                }
            }

            $pendiente = SolicitudRetractacion::where('desvinculacion_id', $desvinculacion->id)
                ->where('estado', 'pendiente')
                ->exists();
            if ($pendiente) {
                return response()->json(['success' => false, 'message' => 'Ya existe una solicitud pendiente para esta renuncia.'], 422);
            }

            $jefe = $this->getJefeEmpleado();

            $solicitud = SolicitudRetractacion::create([
                'desvinculacion_id'          => $desvinculacion->id,
                'empleado_id'                => $desvinculacion->empleado_id,
                'empleado_nombre'            => $desvinculacion->empleado_nombre,
                'sucursal_nombre'            => $desvinculacion->sucursal_nombre,
                'cargo_nombre'               => $desvinculacion->cargo_nombre,
                'solicitado_por_empleado_id' => $jefe->id,
                'solicitado_por_nombre'      => trim($jefe->nombres . ' ' . $jefe->apellidos),
                'justificacion'              => $validated['justificacion'],
                'estado'                     => 'pendiente',
                'aud_usuario'                => Auth::user()->email,
            ]);

            $this->notificarAdminsNuevaSolicitud($solicitud);

            return response()->json(['success' => true, 'data' => $solicitud], 201);
        });
    }

    // ── POST /api/rrhh/retractaciones/{id}/aprobar ─────────────────────────
    public function aprobar(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($id) {
            if (!$this->esAdminRrhh()) {
                return response()->json(['success' => false, 'message' => 'Solo RRHH puede aprobar solicitudes.'], 403);
            }

            $solicitud = SolicitudRetractacion::findOrFail($id);

            if ($solicitud->estado !== 'pendiente') {
                return response()->json(['success' => false, 'message' => 'La solicitud ya fue procesada.'], 422);
            }

            $jefe = $this->getJefeEmpleado();

            DB::transaction(function () use ($solicitud, $jefe) {
                $solicitud->update([
                    'estado'                   => 'aprobada',
                    'revisado_por_empleado_id' => $jefe->id,
                    'revisado_por_nombre'      => trim($jefe->nombres . ' ' . $jefe->apellidos),
                    'revisado_en'              => now(),
                    'aud_usuario'              => Auth::user()->email,
                ]);

                Desvinculacion::where('id', $solicitud->desvinculacion_id)
                    ->update(['estado' => 'retractada', 'aud_usuario' => Auth::user()->email]);

                DB::connection('pgsql')
                    ->table('empleados')
                    ->where('id', $solicitud->empleado_id)
                    ->update(['activo' => true, 'aud_usuario' => 'sistema:retractacion', 'updated_at' => now()]);
            });

            $this->notificarSolicitanteResolucion($solicitud, true, null);

            return response()->json(['success' => true, 'message' => 'Solicitud aprobada. El empleado ha sido reactivado.']);
        });
    }

    // ── POST /api/rrhh/retractaciones/{id}/rechazar ────────────────────────
    public function rechazar(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            if (!$this->esAdminRrhh()) {
                return response()->json(['success' => false, 'message' => 'Solo RRHH puede rechazar solicitudes.'], 403);
            }

            $validated = $request->validate([
                'comentario' => 'required|string|min:5|max:500',
            ]);

            $solicitud = SolicitudRetractacion::findOrFail($id);

            if ($solicitud->estado !== 'pendiente') {
                return response()->json(['success' => false, 'message' => 'La solicitud ya fue procesada.'], 422);
            }

            $jefe = $this->getJefeEmpleado();

            $solicitud->update([
                'estado'                   => 'rechazada',
                'revisado_por_empleado_id' => $jefe->id,
                'revisado_por_nombre'      => trim($jefe->nombres . ' ' . $jefe->apellidos),
                'revisado_en'              => now(),
                'comentario_revision'      => $validated['comentario'],
                'aud_usuario'              => Auth::user()->email,
            ]);

            $this->notificarSolicitanteResolucion($solicitud, false, $validated['comentario']);

            return response()->json(['success' => true, 'message' => 'Solicitud rechazada.']);
        });
    }

    // ── POST /api/rrhh/retractaciones/directa ─────────────────────────────
    // RRHH retracta directamente sin solicitud
    public function directa(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            if (!$this->esAdminRrhh()) {
                return response()->json(['success' => false, 'message' => 'Solo RRHH puede hacer retractaciones directas.'], 403);
            }

            $validated = $request->validate([
                'desvinculacion_id' => 'required|integer',
                'justificacion'     => 'required|string|min:10|max:1000',
            ]);

            $desvinculacion = Desvinculacion::findOrFail($validated['desvinculacion_id']);

            if ($desvinculacion->tipo !== 'renuncia') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden retractar renuncias.'], 422);
            }
            if ($desvinculacion->estado === 'retractada') {
                return response()->json(['success' => false, 'message' => 'Esta renuncia ya fue retractada.'], 422);
            }

            $jefe = $this->getJefeEmpleado();

            DB::transaction(function () use ($desvinculacion, $validated, $jefe) {
                SolicitudRetractacion::create([
                    'desvinculacion_id'          => $desvinculacion->id,
                    'empleado_id'                => $desvinculacion->empleado_id,
                    'empleado_nombre'            => $desvinculacion->empleado_nombre,
                    'sucursal_nombre'            => $desvinculacion->sucursal_nombre,
                    'cargo_nombre'               => $desvinculacion->cargo_nombre,
                    'solicitado_por_empleado_id' => $jefe->id,
                    'solicitado_por_nombre'      => trim($jefe->nombres . ' ' . $jefe->apellidos),
                    'justificacion'              => $validated['justificacion'],
                    'estado'                     => 'aprobada',
                    'revisado_por_empleado_id'   => $jefe->id,
                    'revisado_por_nombre'        => trim($jefe->nombres . ' ' . $jefe->apellidos),
                    'revisado_en'                => now(),
                    'comentario_revision'        => 'Retractación directa por RRHH.',
                    'aud_usuario'                => Auth::user()->email,
                ]);

                Desvinculacion::where('id', $desvinculacion->id)
                    ->update(['estado' => 'retractada', 'aud_usuario' => Auth::user()->email]);

                DB::connection('pgsql')
                    ->table('empleados')
                    ->where('id', $desvinculacion->empleado_id)
                    ->update(['activo' => true, 'aud_usuario' => 'sistema:retractacion', 'updated_at' => now()]);
            });

            return response()->json(['success' => true, 'message' => 'Renuncia retractada. El empleado ha sido reactivado.']);
        });
    }

    // ── Notificaciones ───────────────────────────────────────────────────────

    private function notificarAdminsNuevaSolicitud(SolicitudRetractacion $solicitud): void
    {
        try {
            $admins = DB::connection('pgsql')
                ->table('role_user as ru')
                ->join('roles as r', 'r.id', '=', 'ru.role_id')
                ->join('users as u', 'u.id', '=', 'ru.user_id')
                ->where('r.codigo', 'rrhh_admin')
                ->where('r.system_id', 5)
                ->whereNotNull('u.email')
                ->where('u.recibe_notif_rrhh', true)
                ->where('u.email', '!=', self::EXCLUIR_EMAIL)
                ->select('u.id', 'u.name', 'u.email')
                ->get();

            if ($admins->isEmpty()) return;

            $baseUrl = rtrim(config('app.frontend_rrhh_url', 'https://www.talentohumano.cervezacadejo.com'), '/');
            $link    = "{$baseUrl}/desvinculaciones/renuncias";

            foreach ($admins as $admin) {
                Mail::to($admin->email)->send(new NotificacionAlEmpleado(
                    tipo:               'Solicitud de Retractación de Renuncia',
                    empleadoNombre:     $solicitud->empleado_nombre ?? "Empleado #{$solicitud->empleado_id}",
                    mensaje:            "El jefe {$solicitud->solicitado_por_nombre} ha solicitado retractar la renuncia de este colaborador y requiere su aprobación.",
                    detalles:           [
                        'Colaborador' => $solicitud->empleado_nombre  ?? '—',
                        'Sucursal'    => $solicitud->sucursal_nombre  ?? '—',
                        'Cargo'       => $solicitud->cargo_nombre     ?? '—',
                        'Solicitado por' => $solicitud->solicitado_por_nombre ?? '—',
                        'Justificación'  => $solicitud->justificacion,
                    ],
                    linkUrl:            $link,
                    destinatarioNombre: $admin->name,
                ));
            }
        } catch (\Throwable $e) {
            Log::warning('RRHH: Error notificando solicitud retractación', ['error' => $e->getMessage()]);
        }
    }

    private function notificarSolicitanteResolucion(SolicitudRetractacion $solicitud, bool $aprobada, ?string $comentario): void
    {
        try {
            // Buscar el email del gerente que hizo la solicitud
            $user = DB::connection('pgsql')
                ->table('users as u')
                ->join('empleados as e', 'e.user_id', '=', 'u.id')
                ->where('e.id', $solicitud->solicitado_por_empleado_id)
                ->whereNotNull('u.email')
                ->select('u.name', 'u.email')
                ->first();

            if (!$user) return;

            $estado   = $aprobada ? 'APROBADA ✅' : 'RECHAZADA ❌';
            $detalles = [
                'Colaborador' => $solicitud->empleado_nombre ?? '—',
                'Sucursal'    => $solicitud->sucursal_nombre ?? '—',
                'Resultado'   => $estado,
                'Revisado por'=> $solicitud->revisado_por_nombre ?? '—',
            ];
            if (!$aprobada && $comentario) {
                $detalles['Motivo de rechazo'] = $comentario;
            }

            $baseUrl = rtrim(config('app.frontend_rrhh_url', 'https://www.talentohumano.cervezacadejo.com'), '/');

            Mail::to($user->email)->send(new NotificacionAlEmpleado(
                tipo:               "Retractación de Renuncia — {$estado}",
                empleadoNombre:     $solicitud->empleado_nombre ?? '—',
                mensaje:            $aprobada
                    ? "Tu solicitud de retractación de renuncia fue aprobada. El colaborador ha sido reactivado en el sistema."
                    : "Tu solicitud de retractación de renuncia fue rechazada por RRHH.",
                detalles:           $detalles,
                linkUrl:            "{$baseUrl}/desvinculaciones/renuncias",
                destinatarioNombre: $user->name,
            ));
        } catch (\Throwable $e) {
            Log::warning('RRHH: Error notificando resolución retractación', ['error' => $e->getMessage()]);
        }
    }
}
