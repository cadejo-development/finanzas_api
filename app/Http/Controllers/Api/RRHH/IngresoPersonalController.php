<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\ContratoEmpleado;
use App\Models\RRHH\IngresoPersonal;
use App\Models\RRHH\PeriodoPrueba;
use App\Models\RRHH\PeriodoPruebaCriterio;
use App\Models\RRHH\TipoContrato;
use App\Models\RRHH\PlantillaContrato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class IngresoPersonalController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    /**
     * GET /api/rrhh/ingresos
     */
    public function index(): JsonResponse
    {
        $query = IngresoPersonal::with('periodoPrueba')->orderByDesc('fecha_ingreso');

        if (!$this->esAdminRrhh()) {
            $ids = $this->getSubordinadosIds();
            $query->whereIn('empleado_id', $ids);
        }

        return response()->json(['success' => true, 'data' => $query->get()]);
    }

    /**
     * GET /api/rrhh/ingresos/criterios-evaluacion
     * Devuelve los criterios activos para el formulario de evaluación.
     */
    public function criteriosEvaluacion(): JsonResponse
    {
        $criterios = PeriodoPruebaCriterio::where('activo', true)
            ->orderBy('orden')
            ->get(['id', 'orden', 'pregunta', 'descripcion']);

        return response()->json(['success' => true, 'data' => $criterios]);
    }

    /**
     * POST /api/rrhh/ingresos
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'empleado_id'   => 'required|integer',
            'fecha_ingreso' => 'required|date',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        if (!$this->puedeGestionar($validated['empleado_id'])) {
            return response()->json(['success' => false, 'message' => 'El empleado no pertenece a tu equipo.'], 403);
        }

        $existe = IngresoPersonal::where('empleado_id', $validated['empleado_id'])
            ->where('fecha_ingreso', $validated['fecha_ingreso'])
            ->exists();

        if ($existe) {
            return response()->json(['success' => false, 'message' => 'Ya existe un registro de ingreso para este empleado en esa fecha.'], 422);
        }

        $enriched       = $this->enrichWithEmpleadoData([['empleado_id' => $validated['empleado_id']]]);
        $empleadoNombre = $enriched[0]['empleado_nombre'] ?? "Empleado #{$validated['empleado_id']}";
        $cargoNombre    = $enriched[0]['cargo_nombre']    ?? null;
        $sucursalNombre = $enriched[0]['sucursal_nombre'] ?? null;

        $ingreso = IngresoPersonal::create([
            'empleado_id'      => $validated['empleado_id'],
            'registrado_por_id'=> Auth::id(),
            'empleado_nombre'  => $empleadoNombre,
            'cargo_nombre'     => $cargoNombre,
            'sucursal_nombre'  => $sucursalNombre,
            'fecha_ingreso'    => $validated['fecha_ingreso'],
            'confirmacion'     => 'pendiente',
            'observaciones'    => $validated['observaciones'] ?? null,
            'aud_usuario'      => Auth::user()->email,
        ]);

        $fechaInicio = \Carbon\Carbon::parse($validated['fecha_ingreso']);
        PeriodoPrueba::create([
            'ingreso_id'        => $ingreso->id,
            'empleado_id'       => $validated['empleado_id'],
            'fecha_inicio'      => $fechaInicio,
            'fecha_fin_estimada'=> $fechaInicio->copy()->addDays(PeriodoPrueba::DIAS_DEFAULT),
            'responsable_id'    => Auth::id(),
            'estado'            => 'en_prueba',
            'aud_usuario'       => Auth::user()->email,
        ]);

        $ingreso->load('periodoPrueba');

        try {
            $this->notificarAdminsRrhh(
                tipo:           'Nuevo Ingreso de Personal',
                empleadoNombre: $empleadoNombre,
                detalles: array_filter([
                    'Cargo'                   => $cargoNombre,
                    'Sucursal'                => $sucursalNombre,
                    'Fecha de ingreso'        => $validated['fecha_ingreso'],
                    'Período de prueba hasta' => $fechaInicio->copy()->addDays(PeriodoPrueba::DIAS_DEFAULT)->toDateString(),
                ]),
                rutaFrontend: 'ingresos-personal',
            );
            $ingreso->update(['notificado_admin_en' => now()]);
        } catch (\Throwable $e) {
            Log::warning('IngresoPersonal: error notificando admins', ['error' => $e->getMessage()]);
        }

        return response()->json(['success' => true, 'data' => $ingreso], 201);
    }

    /**
     * GET /api/rrhh/ingresos/{id}
     */
    public function show(int $id): JsonResponse
    {
        $ingreso = IngresoPersonal::with('periodoPrueba')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $ingreso]);
    }

    /**
     * DELETE /api/rrhh/ingresos/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        if (!$this->esAdminRrhh()) {
            return response()->json(['success' => false, 'message' => 'Solo el administrador de RRHH puede eliminar registros de ingreso.'], 403);
        }

        IngresoPersonal::findOrFail($id)->delete();

        return response()->json(['success' => true, 'message' => 'Registro de ingreso eliminado.']);
    }

    /**
     * PATCH /api/rrhh/ingresos/{id}/confirmacion
     * Registra si el empleado se presentó en su primer día.
     * Si se presentó: genera automáticamente el contrato por servicio profesional.
     */
    public function confirmar(Request $request, int $id): JsonResponse
    {
        $ingreso = IngresoPersonal::with('periodoPrueba')->findOrFail($id);

        $validated = $request->validate([
            'confirmacion'       => 'required|in:se_presento,no_show,reprogramado',
            'nueva_fecha_ingreso'=> 'required_if:confirmacion,reprogramado|nullable|date|after:today',
            'observaciones'      => 'nullable|string|max:1000',
        ]);

        $ingreso->update([
            'confirmacion'       => $validated['confirmacion'],
            'confirmado_en'      => now(),
            'confirmado_por_id'  => Auth::id(),
            'nueva_fecha_ingreso'=> $validated['nueva_fecha_ingreso'] ?? null,
            'observaciones'      => $validated['observaciones'] ?? $ingreso->observaciones,
            'aud_usuario'        => Auth::user()->email,
        ]);

        // No Show → notificar a admins RRHH
        if ($validated['confirmacion'] === 'no_show') {
            try {
                $this->notificarAdminsRrhh(
                    tipo:           'No Show — Primer Dia',
                    empleadoNombre: $ingreso->empleado_nombre,
                    detalles: array_filter([
                        'Cargo'          => $ingreso->cargo_nombre,
                        'Sucursal'       => $ingreso->sucursal_nombre,
                        'Fecha esperada' => $ingreso->fecha_ingreso?->toDateString(),
                        'Observaciones'  => $validated['observaciones'] ?? null,
                    ]),
                    rutaFrontend: "ingresos-personal?ver={$ingreso->id}",
                );
            } catch (\Throwable $e) {
                Log::warning('IngresoPersonal: error notificando No Show', ['error' => $e->getMessage()]);
            }
        }

        // Reprogramado → actualizar período de prueba
        if ($validated['confirmacion'] === 'reprogramado' && !empty($validated['nueva_fecha_ingreso'])) {
            $nuevaFecha = \Carbon\Carbon::parse($validated['nueva_fecha_ingreso']);
            $ingreso->periodoPrueba?->update([
                'fecha_inicio'       => $nuevaFecha,
                'fecha_fin_estimada' => $nuevaFecha->copy()->addDays(PeriodoPrueba::DIAS_DEFAULT),
                'aud_usuario'        => Auth::user()->email,
            ]);
        }

        // Se presentó → generar contrato por servicio profesional automáticamente
        $contratoPS = null;
        if ($validated['confirmacion'] === 'se_presento') {
            $contratoPS = $this->autoGenerarContratoPS($ingreso);
        }

        $ingreso->load('periodoPrueba');

        return response()->json([
            'success'    => true,
            'data'       => $ingreso,
            'contrato_ps'=> $contratoPS,
        ]);
    }

    /**
     * PATCH /api/rrhh/ingresos/{id}/periodo-prueba
     * Evalúa el período de prueba con puntaje de criterios.
     * Si estado=aprobado, el puntaje debe ser >= 7. Genera contrato indefinido automáticamente.
     */
    public function actualizarPeriodoPrueba(Request $request, int $id): JsonResponse
    {
        $ingreso = IngresoPersonal::with('periodoPrueba')->findOrFail($id);

        if (!$ingreso->periodoPrueba) {
            return response()->json(['success' => false, 'message' => 'Este ingreso no tiene período de prueba registrado.'], 404);
        }

        $validated = $request->validate([
            'estado'                => 'required|in:' . implode(',', PeriodoPrueba::ESTADOS),
            'comentarios'           => 'nullable|string|max:2000',
            'responsable_id'        => 'nullable|integer',
            'puntaje_evaluacion'    => 'nullable|numeric|min:0|max:10',
            'respuestas_evaluacion' => 'nullable|array',
        ]);

        // Validar que si quiere contratar, el puntaje sea >= 7
        if ($validated['estado'] === 'aprobado') {
            $puntaje = $validated['puntaje_evaluacion'] ?? null;
            if ($puntaje === null || $puntaje < 7) {
                return response()->json([
                    'success' => false,
                    'message' => "El puntaje de evaluación debe ser 7 o mayor para aprobar la contratación. Puntaje actual: {$puntaje}/10.",
                ], 422);
            }
        }

        $periodo = $ingreso->periodoPrueba;
        $periodo->update([
            'estado'                => $validated['estado'],
            'comentarios'           => $validated['comentarios'] ?? $periodo->comentarios,
            'responsable_id'        => $validated['responsable_id'] ?? $periodo->responsable_id,
            'puntaje_evaluacion'    => $validated['puntaje_evaluacion'] ?? $periodo->puntaje_evaluacion,
            'respuestas_evaluacion' => $validated['respuestas_evaluacion'] ?? $periodo->respuestas_evaluacion,
            'evaluado_en'           => in_array($validated['estado'], ['aprobado', 'no_aprobado']) ? now() : $periodo->evaluado_en,
            'evaluado_por_id'       => in_array($validated['estado'], ['aprobado', 'no_aprobado']) ? Auth::id() : $periodo->evaluado_por_id,
            'aud_usuario'           => Auth::user()->email,
        ]);

        // AP → notificar a admins RRHH (sin notificar al empleado)
        if (in_array($validated['estado'], ['aprobado', 'no_aprobado'])) {
            try {
                $tipoAP  = $validated['estado'] === 'aprobado'
                    ? 'AP de Contratacion — Contrato Indefinido'
                    : 'AP de No Contratacion — Fin de Periodo de Prueba';
                $accion  = $validated['estado'] === 'aprobado'
                    ? "Período aprobado (puntaje {$validated['puntaje_evaluacion']}/10). Proceder con la emisión del contrato indefinido."
                    : "Período no aprobado. Proceder con la notificación formal al colaborador.";

                $this->notificarAdminsRrhh(
                    tipo:           $tipoAP,
                    empleadoNombre: $ingreso->empleado_nombre,
                    detalles: array_filter([
                        'Cargo'          => $ingreso->cargo_nombre,
                        'Sucursal'       => $ingreso->sucursal_nombre,
                        'Puntaje'        => ($validated['puntaje_evaluacion'] ?? '—') . '/10',
                        'Fin de periodo' => $periodo->fecha_fin_estimada?->toDateString(),
                        'Comentarios'    => $validated['comentarios'] ?? null,
                        'Acción'         => $accion,
                    ]),
                    rutaFrontend: "ingresos-personal?ver={$ingreso->id}",
                );
            } catch (\Throwable $e) {
                Log::warning('IngresoPersonal: error notificando AP contratación', ['error' => $e->getMessage()]);
            }
        }

        // Renuncia / Desvinculado → notificar a admins RRHH (proceso interno)
        if (in_array($validated['estado'], ['renuncia', 'desvinculado'])) {
            try {
                $labels = [
                    'renuncia'     => 'Periodo de Prueba — Renuncia',
                    'desvinculado' => 'Periodo de Prueba — Desvinculacion',
                ];
                $this->notificarAdminsRrhh(
                    tipo:           $labels[$validated['estado']],
                    empleadoNombre: $ingreso->empleado_nombre,
                    detalles: array_filter([
                        'Cargo'        => $ingreso->cargo_nombre,
                        'Sucursal'     => $ingreso->sucursal_nombre,
                        'Comentarios'  => $validated['comentarios'] ?? null,
                    ]),
                    rutaFrontend: "ingresos-personal?ver={$ingreso->id}",
                );
            } catch (\Throwable $e) {
                Log::warning('IngresoPersonal: error notificando renuncia/desvinculación período', ['error' => $e->getMessage()]);
            }
        }

        // Aprobado → generar contrato indefinido automáticamente
        $contratoIndefinido = null;
        if ($validated['estado'] === 'aprobado') {
            $contratoIndefinido = $this->autoGenerarContratoIndefinido($ingreso);
        }

        $ingreso->load('periodoPrueba');

        return response()->json([
            'success'             => true,
            'data'                => $ingreso,
            'contrato_indefinido' => $contratoIndefinido,
        ]);
    }

    // ── Helpers de contratos automáticos ────────────────────────────────────────

    /**
     * Genera automáticamente el contrato por servicio profesional (período de prueba).
     * Busca el TipoContrato con es_periodo_prueba=true y su primera plantilla activa.
     * Devuelve el contrato creado o null si no hay tipo/plantilla configurado.
     */
    private function autoGenerarContratoPS(IngresoPersonal $ingreso): ?array
    {
        try {
            $tipo = TipoContrato::where('es_periodo_prueba', true)->where('activo', true)->first();
            if (!$tipo) return null;

            $plantilla = PlantillaContrato::where('tipo_contrato_id', $tipo->id)
                ->where('activo', true)
                ->orderBy('id')
                ->first();

            $contrato = ContratoEmpleado::create([
                'empleado_id'     => $ingreso->empleado_id,
                'empleado_nombre' => $ingreso->empleado_nombre,
                'ingreso_id'      => $ingreso->id,
                'tipo_contrato_id'=> $tipo->id,
                'plantilla_id'    => $plantilla?->id,
                'fecha_inicio'    => $ingreso->fecha_ingreso,
                'fecha_fin'       => $ingreso->periodoPrueba?->fecha_fin_estimada,
                'estado'          => 'activo',
                'generado_por_id' => Auth::id(),
                'aud_usuario'     => Auth::user()->email,
            ]);

            $contrato->load('tipoContrato');

            return [
                'id'           => $contrato->id,
                'tipo'         => $contrato->tipoContrato?->nombre,
                'tiene_pdf'    => $plantilla !== null,
                'plantilla_id' => $plantilla?->id,
            ];
        } catch (\Throwable $e) {
            Log::warning('IngresoPersonal: error auto-generando contrato PS', ['error' => $e->getMessage(), 'ingreso_id' => $ingreso->id]);
            return null;
        }
    }

    /**
     * Genera automáticamente el contrato indefinido al aprobar el período de prueba.
     */
    private function autoGenerarContratoIndefinido(IngresoPersonal $ingreso): ?array
    {
        try {
            $tipo = TipoContrato::where('es_periodo_prueba', false)
                ->where('activo', true)
                ->whereNull('duracion_dias')
                ->first();

            if (!$tipo) return null;

            $plantilla = PlantillaContrato::where('tipo_contrato_id', $tipo->id)
                ->where('activo', true)
                ->orderBy('id')
                ->first();

            $fechaInicio = now()->addDay()->toDateString();

            $contrato = ContratoEmpleado::create([
                'empleado_id'     => $ingreso->empleado_id,
                'empleado_nombre' => $ingreso->empleado_nombre,
                'ingreso_id'      => $ingreso->id,
                'tipo_contrato_id'=> $tipo->id,
                'plantilla_id'    => $plantilla?->id,
                'fecha_inicio'    => $fechaInicio,
                'fecha_fin'       => null,
                'estado'          => 'activo',
                'generado_por_id' => Auth::id(),
                'aud_usuario'     => Auth::user()->email,
            ]);

            $contrato->load('tipoContrato');

            return [
                'id'           => $contrato->id,
                'tipo'         => $contrato->tipoContrato?->nombre,
                'tiene_pdf'    => $plantilla !== null,
                'plantilla_id' => $plantilla?->id,
            ];
        } catch (\Throwable $e) {
            Log::warning('IngresoPersonal: error auto-generando contrato indefinido', ['error' => $e->getMessage(), 'ingreso_id' => $ingreso->id]);
            return null;
        }
    }
}
