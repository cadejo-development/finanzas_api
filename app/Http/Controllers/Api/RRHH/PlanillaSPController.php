<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\PlanillaSP;
use App\Models\RRHH\PlanillaSPLinea;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlanillaSPController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    // Retención ISR fija para servicios profesionales (Art. 156 Ley ISR El Salvador)
    private const RETENCION_ISR_PCT = 0.10;

    // ─── Listado ─────────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas-sp
     */
    public function index(Request $request): JsonResponse
    {
        $query = PlanillaSP::query();

        if ($anio = $request->input('anio'))   $query->where('anio', (int) $anio);
        if ($mes  = $request->input('mes'))    $query->where('mes',  (int) $mes);

        $planillas = $query->orderByDesc('anio')->orderByDesc('mes')->orderByDesc('id')->get();

        $data = $planillas->map(function ($p) {
            $arr = $p->toArray();
            $arr['lineas_count'] = $p->lineas()->count();
            return $arr;
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    // ─── Detalle ─────────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas-sp/{id}
     */
    public function show(int $id): JsonResponse
    {
        $planilla = PlanillaSP::with('lineas')->findOrFail($id);
        return response()->json(['success' => true, 'data' => $planilla]);
    }

    // ─── Crear planilla ───────────────────────────────────────────────────────

    /**
     * POST /api/rrhh/planillas-sp
     */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $validated = $request->validate([
                'anio'        => 'required|integer|min:2020|max:2099',
                'mes'         => 'required|integer|min:1|max:12',
                'descripcion' => 'nullable|string|max:200',
                'sucursal_id' => 'nullable|integer',
            ]);

            $planilla = PlanillaSP::create([
                'anio'        => (int) $validated['anio'],
                'mes'         => (int) $validated['mes'],
                'descripcion' => $validated['descripcion'] ?? null,
                'sucursal_id' => isset($validated['sucursal_id']) ? (int) $validated['sucursal_id'] : null,
                'estado'      => 'borrador',
                'creado_por'  => Auth::id(),
            ]);

            return response()->json(['success' => true, 'data' => $planilla], 201);
        });
    }

    // ─── Cerrar ───────────────────────────────────────────────────────────────

    /**
     * PUT /api/rrhh/planillas-sp/{id}/cerrar
     */
    public function cerrar(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se puede cerrar una planilla SP en borrador.'], 422);
            }

            $planilla->update(['estado' => 'cerrada']);

            return response()->json(['success' => true, 'message' => 'Planilla SP cerrada.', 'data' => $planilla->fresh()]);
        });
    }

    // ─── Eliminar planilla ────────────────────────────────────────────────────

    /**
     * DELETE /api/rrhh/planillas-sp/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden eliminar planillas SP en borrador.'], 422);
            }

            $planilla->delete();
            return response()->json(['success' => true, 'message' => 'Planilla SP eliminada.']);
        });
    }

    // ─── Líneas CRUD ─────────────────────────────────────────────────────────

    /**
     * POST /api/rrhh/planillas-sp/{id}/lineas
     * Agrega un prestador de servicios a la planilla.
     */
    public function storeLinea(int $id, Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($id, $request) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'Solo se pueden agregar líneas a planillas SP en borrador.'], 422);
            }

            $validated = $request->validate([
                'nombre'              => 'required|string|max:150',
                'nit'                 => 'nullable|string|max:20',
                'descripcion_servicio'=> 'nullable|string|max:200',
                'monto_honorario'     => 'required|numeric|min:0.01',
                'notas'               => 'nullable|string',
            ]);

            $monto     = round((float) $validated['monto_honorario'], 2);
            $retencion = round($monto * self::RETENCION_ISR_PCT, 2);
            $liquido   = round($monto - $retencion, 2);

            DB::connection('rrhh')->beginTransaction();
            try {
                $linea = PlanillaSPLinea::create([
                    'planilla_sp_id'       => $id,
                    'nombre'               => $validated['nombre'],
                    'nit'                  => $validated['nit'] ?? null,
                    'descripcion_servicio' => $validated['descripcion_servicio'] ?? null,
                    'monto_honorario'      => $monto,
                    'retencion_isr'        => $retencion,
                    'liquido'              => $liquido,
                    'notas'                => $validated['notas'] ?? null,
                ]);

                $this->recalcularTotales($planilla);
                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return response()->json(['success' => true, 'data' => $linea], 201);
        });
    }

    /**
     * PUT /api/rrhh/planillas-sp/{id}/lineas/{lineaId}
     */
    public function updateLinea(int $id, int $lineaId, Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($id, $lineaId, $request) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'No se puede editar una planilla SP cerrada.'], 422);
            }

            $linea = PlanillaSPLinea::where('planilla_sp_id', $id)->findOrFail($lineaId);

            $validated = $request->validate([
                'nombre'              => 'sometimes|string|max:150',
                'nit'                 => 'nullable|string|max:20',
                'descripcion_servicio'=> 'nullable|string|max:200',
                'monto_honorario'     => 'sometimes|numeric|min:0.01',
                'notas'               => 'nullable|string',
            ]);

            if (isset($validated['monto_honorario'])) {
                $monto = round((float) $validated['monto_honorario'], 2);
                $validated['monto_honorario'] = $monto;
                $validated['retencion_isr']   = round($monto * self::RETENCION_ISR_PCT, 2);
                $validated['liquido']         = round($monto - $validated['retencion_isr'], 2);
            }

            DB::connection('rrhh')->beginTransaction();
            try {
                $linea->update($validated);
                $this->recalcularTotales($planilla);
                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            return response()->json(['success' => true, 'data' => $linea->fresh()]);
        });
    }

    /**
     * DELETE /api/rrhh/planillas-sp/{id}/lineas/{lineaId}
     */
    public function destroyLinea(int $id, int $lineaId): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id, $lineaId) {
            $planilla = PlanillaSP::findOrFail($id);

            if ($planilla->estado !== 'borrador') {
                return response()->json(['success' => false, 'message' => 'No se puede eliminar líneas de una planilla SP cerrada.'], 422);
            }

            $deleted = PlanillaSPLinea::where('planilla_sp_id', $id)->where('id', $lineaId)->delete();

            if (!$deleted) {
                return response()->json(['success' => false, 'message' => 'Línea no encontrada.'], 404);
            }

            $this->recalcularTotales($planilla);

            return response()->json(['success' => true, 'message' => 'Línea eliminada.']);
        });
    }

    // ─── Exportar ─────────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas-sp/{id}/exportar
     */
    public function exportar(int $id): JsonResponse
    {
        $planilla = PlanillaSP::with('lineas')->findOrFail($id);

        return response()->json([
            'success' => true,
            'data'    => [
                'planilla' => $planilla,
                'lineas'   => $planilla->lineas,
                'totales'  => [
                    'total_honorarios' => (float) $planilla->total_honorarios,
                    'total_retencion'  => (float) $planilla->total_retencion,
                    'total_neto'       => (float) $planilla->total_neto,
                ],
            ],
        ]);
    }

    // ─── Helper ───────────────────────────────────────────────────────────────

    private function recalcularTotales(PlanillaSP $planilla): void
    {
        $lineas = PlanillaSPLinea::where('planilla_sp_id', $planilla->id)->get();
        $planilla->update([
            'total_honorarios' => round($lineas->sum(fn($l) => (float) $l->monto_honorario), 2),
            'total_retencion'  => round($lineas->sum(fn($l) => (float) $l->retencion_isr), 2),
            'total_neto'       => round($lineas->sum(fn($l) => (float) $l->liquido), 2),
        ]);
    }
}
