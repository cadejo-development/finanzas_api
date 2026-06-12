<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\IngresoPersonal;
use App\Models\RRHH\PeriodoPrueba;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class IngresoPersonalController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    /**
     * GET /api/rrhh/ingresos
     * Lista todos los ingresos con su período de prueba.
     */
    public function index(): JsonResponse
    {
        $query = IngresoPersonal::with('periodoPrueba')->orderByDesc('fecha_ingreso');

        // Jefatura: solo empleados a su cargo
        if (!$this->esAdminRrhh()) {
            $ids = $this->getSubordinadosIds();
            $query->whereIn('empleado_id', $ids);
        }

        $ingresos = $query->get();

        return response()->json(['success' => true, 'data' => $ingresos]);
    }

    /**
     * POST /api/rrhh/ingresos
     * Registra un nuevo ingreso y crea automáticamente su período de prueba.
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

        // Evitar duplicados
        $existe = IngresoPersonal::where('empleado_id', $validated['empleado_id'])
            ->where('fecha_ingreso', $validated['fecha_ingreso'])
            ->exists();

        if ($existe) {
            return response()->json(['success' => false, 'message' => 'Ya existe un registro de ingreso para este empleado en esa fecha.'], 422);
        }

        // Enriquecer con datos del empleado
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

        // Crear período de prueba automáticamente (90 días)
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

        // Notificar a admins RRHH del nuevo ingreso
        try {
            $this->notificarAdminsRrhh(
                tipo:           'Nuevo Ingreso de Personal',
                empleadoNombre: $empleadoNombre,
                detalles: array_filter([
                    'Cargo'          => $cargoNombre,
                    'Sucursal'       => $sucursalNombre,
                    'Fecha de ingreso'=> $validated['fecha_ingreso'],
                    'Período de prueba hasta' => $fechaInicio->copy()->addDays(PeriodoPrueba::DIAS_DEFAULT)->toDateString(),
                ]),
                rutaFrontend: 'ingresos-personal',
            );
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
     * Solo admin RRHH puede eliminar un ingreso (y su período de prueba en cascada).
     */
    public function destroy(int $id): JsonResponse
    {
        if (!$this->esAdminRrhh()) {
            return response()->json(['success' => false, 'message' => 'Solo el administrador de RRHH puede eliminar registros de ingreso.'], 403);
        }

        $ingreso = IngresoPersonal::findOrFail($id);
        $ingreso->delete(); // cascade borra período de prueba

        return response()->json(['success' => true, 'message' => 'Registro de ingreso eliminado.']);
    }

    /**
     * PATCH /api/rrhh/ingresos/{id}/confirmacion
     * Registra si el empleado se presentó en su primer día.
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

        // No Show: notificar a admins RRHH
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

        // Reprogramado: actualizar fecha de inicio del período de prueba
        if ($validated['confirmacion'] === 'reprogramado' && !empty($validated['nueva_fecha_ingreso'])) {
            $nuevaFecha = \Carbon\Carbon::parse($validated['nueva_fecha_ingreso']);
            $ingreso->periodoPrueba?->update([
                'fecha_inicio'       => $nuevaFecha,
                'fecha_fin_estimada' => $nuevaFecha->copy()->addDays(PeriodoPrueba::DIAS_DEFAULT),
                'aud_usuario'        => Auth::user()->email,
            ]);
        }

        $ingreso->load('periodoPrueba');

        return response()->json(['success' => true, 'data' => $ingreso]);
    }

    /**
     * PATCH /api/rrhh/ingresos/{id}/periodo-prueba
     * Actualiza el estado y comentarios del período de prueba.
     */
    public function actualizarPeriodoPrueba(Request $request, int $id): JsonResponse
    {
        $ingreso = IngresoPersonal::with('periodoPrueba')->findOrFail($id);

        if (!$ingreso->periodoPrueba) {
            return response()->json(['success' => false, 'message' => 'Este ingreso no tiene período de prueba registrado.'], 404);
        }

        $validated = $request->validate([
            'estado'         => 'required|in:' . implode(',', PeriodoPrueba::ESTADOS),
            'comentarios'    => 'nullable|string|max:2000',
            'responsable_id' => 'nullable|integer',
        ]);

        $periodo = $ingreso->periodoPrueba;
        $periodo->update([
            'estado'         => $validated['estado'],
            'comentarios'    => $validated['comentarios'] ?? $periodo->comentarios,
            'responsable_id' => $validated['responsable_id'] ?? $periodo->responsable_id,
            'evaluado_en'    => in_array($validated['estado'], ['aprobado', 'no_aprobado']) ? now() : $periodo->evaluado_en,
            'evaluado_por_id'=> in_array($validated['estado'], ['aprobado', 'no_aprobado']) ? Auth::id() : $periodo->evaluado_por_id,
            'aud_usuario'    => Auth::user()->email,
        ]);

        // Notificar al empleado cuando el período termina
        $estadosFinales = ['aprobado', 'no_aprobado', 'renuncia', 'desvinculado'];
        if (in_array($validated['estado'], $estadosFinales)) {
            try {
                $labels = [
                    'aprobado'     => 'Período de Prueba Aprobado',
                    'no_aprobado'  => 'Período de Prueba No Aprobado',
                    'renuncia'     => 'Período de Prueba — Renuncia',
                    'desvinculado' => 'Período de Prueba — Desvinculación',
                ];
                $this->notificarAlEmpleado(
                    empleadoId:   $ingreso->empleado_id,
                    tipo:         $labels[$validated['estado']],
                    mensaje:      "El estado de tu período de prueba ha sido actualizado.",
                    detalles: array_filter([
                        'Estado'      => $labels[$validated['estado']],
                        'Comentarios' => $validated['comentarios'] ?? null,
                        'Evaluado en' => now()->toDateString(),
                    ]),
                    rutaFrontend: 'mi-expediente',
                    pdfContent:   null,
                    pdfNombre:    null,
                );
            } catch (\Throwable $e) {
                Log::warning('IngresoPersonal: error notificando resultado período', ['error' => $e->getMessage()]);
            }
        }

        $ingreso->load('periodoPrueba');

        return response()->json(['success' => true, 'data' => $ingreso]);
    }
}
