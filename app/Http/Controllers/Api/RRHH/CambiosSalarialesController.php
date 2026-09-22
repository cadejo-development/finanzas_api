<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Mail\RRHH\NotificacionAlEmpleado;
use App\Models\RRHH\CambioSalarial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class CambiosSalarialesController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    /**
     * GET /api/rrhh/cambios-salariales
     * El Gerente Financiero ve todas las nivelaciones; jefatura ve solo las de su equipo.
     */
    public function index(Request $request): JsonResponse
    {
        $query = CambioSalarial::with('tipoAumento')->orderByDesc('id');

        if (!$this->esGerenteFinanciero() && !$this->esAdminRrhh()) {
            $subordinadosIds = $this->getSubordinadosIds();
            $query->whereIn('empleado_id', $subordinadosIds);
        }

        if ($request->filled('tipo_aumento_id')) {
            $query->where('tipo_aumento_id', $request->tipo_aumento_id);
        }

        $cambios = $query->get();
        $data = $this->enrichWithEmpleadoData($cambios->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/cambios-salariales
     */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $jefe = $this->getJefeEmpleado();

            $validated = $request->validate([
                'empleado_id'     => 'required|integer',
                'tipo_aumento_id' => 'required|exists:rrhh.tipos_aumento_salarial,id',
                'salario_anterior'=> 'required|numeric|min:0',
                'salario_nuevo'   => 'required|numeric|min:0|gt:salario_anterior',
                'fecha_efectiva'  => 'required|date',
                'justificacion'   => 'nullable|string|max:1000',
            ]);

            if (!$this->puedeGestionar($validated['empleado_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'El empleado no pertenece a tu equipo.',
                ], 403);
            }

            // Calcular porcentaje automáticamente
            $porcentaje = $validated['salario_anterior'] > 0
                ? round((($validated['salario_nuevo'] - $validated['salario_anterior']) / $validated['salario_anterior']) * 100, 2)
                : null;

            $cambio = CambioSalarial::create(array_merge($validated, [
                'solicitado_por_id'  => $jefe->id,
                'porcentaje'         => $porcentaje,
                'estado'             => 'pendiente',
                'aprobacion_token'   => (string) Str::uuid(),
                'aud_usuario'        => Auth::user()->email,
            ]));

            $cambio->load('tipoAumento');

            $arr = $this->enrichWithEmpleadoData([$cambio->toArray()]);
            $enriched = $arr[0];

            $empleadoNombre = $enriched['empleado_nombre'] ?? 'Empleado #' . $cambio->empleado_id;

            // Notificar al Gerente Financiero con botones de aprobación/rechazo en el correo
            $this->notificarGerenteFinanciero(
                cambio:         $cambio,
                empleadoNombre: $empleadoNombre,
                detalles: [
                    'Tipo de aumento'  => $cambio->tipoAumento?->nombre ?? '—',
                    'Salario actual'   => '$' . number_format((float)$cambio->salario_anterior, 2),
                    'Salario nivelado' => '$' . number_format((float)$cambio->salario_nuevo, 2),
                    'Porcentaje'       => $porcentaje !== null ? $porcentaje . '%' : '—',
                    'Fecha efectiva'   => $cambio->fecha_efectiva?->format('d/m/Y') ?? '—',
                    'Justificación'    => $cambio->justificacion ?? '—',
                    'Cargo'            => $enriched['cargo_nombre'] ?? '—',
                    'Sucursal'         => $enriched['sucursal_nombre'] ?? '—',
                    'Solicitado por'   => trim($jefe->nombres . ' ' . $jefe->apellidos),
                ],
            );

            return response()->json(['success' => true, 'data' => $enriched], 201);
        });
    }

    /**
     * GET /api/rrhh/cambios-salariales/{id}
     */
    public function show(int $id): JsonResponse
    {
        $cambio = CambioSalarial::with('tipoAumento')->findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$cambio->toArray()]);

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * PUT /api/rrhh/cambios-salariales/{id}
     * El cambio de estado (aprobado/rechazado) dispara notificaciones.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $cambio = CambioSalarial::findOrFail($id);
            $estadoAnterior = $cambio->estado;

            $validated = $request->validate([
                'tipo_aumento_id' => 'sometimes|exists:rrhh.tipos_aumento_salarial,id',
                'salario_anterior'=> 'sometimes|numeric|min:0',
                'salario_nuevo'   => 'sometimes|numeric|min:0',
                'fecha_efectiva'  => 'sometimes|date',
                'justificacion'   => 'nullable|string|max:1000',
                'motivo_rechazo'  => 'nullable|string|max:500',
                'estado'          => 'sometimes|in:pendiente,aprobado,rechazado',
            ]);

            // Recalcular porcentaje si cambia algún salario
            $anterior = $validated['salario_anterior'] ?? $cambio->salario_anterior;
            $nuevo    = $validated['salario_nuevo']    ?? $cambio->salario_nuevo;

            if (isset($validated['salario_anterior']) || isset($validated['salario_nuevo'])) {
                $validated['porcentaje'] = $anterior > 0
                    ? round((($nuevo - $anterior) / $anterior) * 100, 2)
                    : null;
            }

            $cambio->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));
            $cambio->load('tipoAumento');

            // Notificaciones al cambiar el estado de pendiente a aprobado/rechazado
            $nuevoEstado = $cambio->estado;
            if ($estadoAnterior === 'pendiente' && in_array($nuevoEstado, ['aprobado', 'rechazado'])) {
                $this->enviarNotificacionesResolucion($cambio, $nuevoEstado);
            }

            return response()->json(['success' => true, 'data' => $cambio]);
        });
    }

    /**
     * DELETE /api/rrhh/cambios-salariales/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            CambioSalarial::findOrFail($id)->delete();
            return response()->json(['success' => true, 'message' => 'Cambio salarial eliminado.']);
        });
    }

    // ─── Revisión por email (rutas públicas, sin auth) ────────────────────────

    /**
     * GET /api/public/nivelacion-revision/{token}
     * Devuelve los detalles de la nivelación para que el GER_FIN pueda revisarla.
     */
    public function mostrarRevision(string $token): JsonResponse
    {
        $cambio = CambioSalarial::with('tipoAumento')
            ->where('aprobacion_token', $token)
            ->first();

        if (!$cambio) {
            return response()->json(['success' => false, 'message' => 'Enlace inválido o expirado.'], 404);
        }

        $arr = $this->enrichWithEmpleadoData([$cambio->toArray()]);
        $enriched = $arr[0];

        return response()->json([
            'success' => true,
            'data' => [
                'id'               => $cambio->id,
                'estado'           => $cambio->estado,
                'empleado_nombre'  => $enriched['empleado_nombre'] ?? '—',
                'cargo_nombre'     => $enriched['cargo_nombre'] ?? '—',
                'sucursal_nombre'  => $enriched['sucursal_nombre'] ?? '—',
                'tipo_aumento'     => $cambio->tipoAumento?->nombre ?? '—',
                'salario_anterior' => $cambio->salario_anterior,
                'salario_nuevo'    => $cambio->salario_nuevo,
                'porcentaje'       => $cambio->porcentaje,
                'fecha_efectiva'   => $cambio->fecha_efectiva?->format('Y-m-d'),
                'justificacion'    => $cambio->justificacion,
                'motivo_rechazo'   => $cambio->motivo_rechazo,
            ],
        ]);
    }

    /**
     * POST /api/public/nivelacion-revision/{token}
     * Procesa la aprobación o rechazo desde el correo (sin auth).
     */
    public function procesarRevision(Request $request, string $token): JsonResponse
    {
        $cambio = CambioSalarial::with('tipoAumento')
            ->where('aprobacion_token', $token)
            ->first();

        if (!$cambio) {
            return response()->json(['success' => false, 'message' => 'Enlace inválido o expirado.'], 404);
        }

        if ($cambio->estado !== 'pendiente') {
            return response()->json([
                'success' => false,
                'message' => 'Esta nivelación ya fue ' . ($cambio->estado === 'aprobado' ? 'aprobada' : 'rechazada') . '.',
                'estado'  => $cambio->estado,
            ], 409);
        }

        $validated = $request->validate([
            'accion'          => 'required|in:aprobar,rechazar',
            'motivo_rechazo'  => 'nullable|string|max:500',
        ]);

        if ($validated['accion'] === 'rechazar' && empty(trim($validated['motivo_rechazo'] ?? ''))) {
            return response()->json(['success' => false, 'message' => 'El motivo de rechazo es obligatorio.'], 422);
        }

        $nuevoEstado = $validated['accion'] === 'aprobar' ? 'aprobado' : 'rechazado';

        $cambio->update([
            'estado'          => $nuevoEstado,
            'motivo_rechazo'  => $nuevoEstado === 'rechazado' ? trim($validated['motivo_rechazo']) : null,
            'aud_usuario'     => 'revision-email',
        ]);

        $cambio->load('tipoAumento');

        // Si el registro tiene test_notif_para, redirigir todos los correos
        // de esta resolución a ese email (útil para pruebas sin tocar env de producción).
        if ($cambio->test_notif_para) {
            Mail::alwaysTo($cambio->test_notif_para);
        }

        $this->enviarNotificacionesResolucion($cambio, $nuevoEstado);

        return response()->json([
            'success' => true,
            'estado'  => $nuevoEstado,
            'message' => $nuevoEstado === 'aprobado'
                ? 'Nivelación aprobada correctamente. Se notificó al equipo de RRHH y al solicitante.'
                : 'Nivelación rechazada. Se notificó al solicitante.',
        ]);
    }

    // ─── Helpers privados ─────────────────────────────────────────────────────

    private function esGerenteFinanciero(): bool
    {
        try {
            $jefeId = $this->getJefeEmpleado()->id;
            return DB::connection('pgsql')
                ->table('departamentos')
                ->where('nombre', 'GERENCIA FINANCIERA')
                ->where('jefe_empleado_id', $jefeId)
                ->exists();
        } catch (\Throwable) {
            return false;
        }
    }

    private function notificarGerenteFinanciero(CambioSalarial $cambio, string $empleadoNombre, array $detalles): void
    {
        try {
            $dept = DB::connection('pgsql')
                ->table('departamentos')
                ->where('nombre', 'GERENCIA FINANCIERA')
                ->select('jefe_empleado_id')
                ->first();

            if (!$dept?->jefe_empleado_id) return;

            $empleado = DB::connection('rrhh')
                ->table('empleados')
                ->where('id', $dept->jefe_empleado_id)
                ->select('nombres', 'apellidos', 'user_id')
                ->first();

            if (!$empleado?->user_id) return;

            $user = DB::connection('pgsql')
                ->table('users')
                ->where('id', $empleado->user_id)
                ->whereNotNull('email')
                ->select('name', 'email')
                ->first();

            if (!$user) return;

            $baseUrl    = rtrim(config('app.frontend_rrhh_url', 'https://www.talentohumano.cervezacadejo.com'), '/');
            $baseApiUrl = rtrim(config('app.url', ''), '/');
            $tokenPath  = "/api/public/nivelacion-revision/{$cambio->aprobacion_token}";

            $mailable = new NotificacionAlEmpleado(
                tipo:               'Nivelación Salarial — Pendiente de aprobación',
                empleadoNombre:     $empleadoNombre,
                mensaje:            'Se ha registrado una nueva Nivelación Salarial que requiere su aprobación. Por favor, revise los detalles y tome una decisión.',
                detalles:           $detalles,
                linkUrl:            "{$baseUrl}/modificaciones/nivelacion-salarial",
                destinatarioNombre: $user->name,
                aprobarUrl:         "{$baseUrl}/nivelacion/revision/{$cambio->aprobacion_token}?accion=aprobar",
                rechazarUrl:        "{$baseUrl}/nivelacion/revision/{$cambio->aprobacion_token}?accion=rechazar",
            );

            Mail::to($user->email)->send($mailable);

            $this->registrarEmailLog([
                'tipo'            => 'notificacion_gerente_financiero',
                'destinatario'    => $user->email,
                'asunto'          => $mailable->envelope()->subject,
                'estado'          => 'enviado',
                'enviado_por'     => Auth::user()?->email ?? 'sistema',
                'referencia_tipo' => 'empleado',
            ]);
        } catch (\Throwable $e) {
            Log::warning('RRHH: Error notificando al Gerente Financiero', ['error' => $e->getMessage()]);
        }
    }

    private function enviarNotificacionesResolucion(CambioSalarial $cambio, string $estado): void
    {
        $arr = $this->enrichWithEmpleadoData([$cambio->toArray()]);
        $enriched = $arr[0];
        $empleadoNombre = $enriched['empleado_nombre'] ?? 'Empleado #' . $cambio->empleado_id;

        $detallesNotif = [
            'Tipo de aumento'  => $cambio->tipoAumento?->nombre ?? '—',
            'Salario anterior'  => '$' . number_format((float)$cambio->salario_anterior, 2),
            'Salario nuevo'     => '$' . number_format((float)$cambio->salario_nuevo, 2),
            'Porcentaje'        => $cambio->porcentaje !== null ? $cambio->porcentaje . '%' : '—',
            'Fecha efectiva'    => $cambio->fecha_efectiva?->format('d/m/Y') ?? '—',
            'Cargo'             => $enriched['cargo_nombre'] ?? '—',
            'Sucursal'          => $enriched['sucursal_nombre'] ?? '—',
        ];

        if ($estado === 'rechazado' && $cambio->motivo_rechazo) {
            $detallesNotif['Motivo del rechazo'] = $cambio->motivo_rechazo;
        }

        if ($estado === 'aprobado') {
            $this->notificarAdminsRrhh(
                tipo:           'Nivelación Salarial Aprobada',
                empleadoNombre: $empleadoNombre,
                detalles:       $detallesNotif,
                rutaFrontend:   'modificaciones/nivelacion-salarial',
            );
        }

        $this->notificarSolicitador($cambio, $estado, $empleadoNombre, $detallesNotif);
    }

    private function notificarSolicitador(CambioSalarial $cambio, string $estado, string $empleadoNombre, array $detalles): void
    {
        try {
            if (!$cambio->solicitado_por_id) return;

            $solicitador = DB::connection('rrhh')
                ->table('empleados')
                ->where('id', $cambio->solicitado_por_id)
                ->select('nombres', 'apellidos', 'user_id')
                ->first();

            if (!$solicitador?->user_id) return;

            $user = DB::connection('pgsql')
                ->table('users')
                ->where('id', $solicitador->user_id)
                ->whereNotNull('email')
                ->select('name', 'email')
                ->first();

            if (!$user) return;

            $baseUrl = rtrim(config('app.frontend_rrhh_url', 'https://www.talentohumano.cervezacadejo.com'), '/');

            $tipo    = $estado === 'aprobado' ? 'Nivelación Salarial Aprobada' : 'Nivelación Salarial Rechazada';
            $mensaje = $estado === 'aprobado'
                ? 'La nivelación salarial que solicitaste ha sido aprobada.'
                : 'La nivelación salarial que solicitaste ha sido rechazada. Revisa los detalles para más información.';

            $mailable = new NotificacionAlEmpleado(
                tipo:               $tipo,
                empleadoNombre:     $empleadoNombre,
                mensaje:            $mensaje,
                detalles:           $detalles,
                linkUrl:            "{$baseUrl}/modificaciones/nivelacion-salarial",
                destinatarioNombre: $user->name,
            );

            Mail::to($user->email)->send($mailable);

            $this->registrarEmailLog([
                'tipo'            => 'notificacion_solicitador_nivelacion',
                'destinatario'    => $user->email,
                'asunto'          => $mailable->envelope()->subject,
                'estado'          => 'enviado',
                'enviado_por'     => Auth::user()?->email ?? 'sistema',
                'referencia_tipo' => 'empleado',
            ]);
        } catch (\Throwable $e) {
            Log::warning('RRHH: Error notificando al solicitador de nivelación', ['error' => $e->getMessage()]);
        }
    }
}
