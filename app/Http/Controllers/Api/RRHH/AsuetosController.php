<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\Asueto;
use App\Models\GeoDepartamento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AsuetosController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    /**
     * GET /api/rrhh/asuetos
     * Query params: year, month (optional)
     */
    public function index(Request $request): JsonResponse
    {
        $query = Asueto::with('geoDepartamento:id,nombre,codigo')
            ->where('activo', true)
            ->orderBy('fecha');

        if ($year = $request->integer('year')) {
            $query->whereYear('fecha', $year);
        }
        if ($month = $request->integer('month')) {
            $query->whereMonth('fecha', $month);
        }

        $asuetos = $query->get()->map(fn ($a) => [
            'id'                  => $a->id,
            'fecha'               => $a->fecha->format('Y-m-d'),
            'nombre'              => $a->nombre,
            'tipo'                => $a->tipo,
            'geo_departamento_id' => $a->geo_departamento_id,
            'geo_departamento'    => $a->geoDepartamento?->nombre,
            'activo'              => $a->activo,
        ]);

        $departamentos = GeoDepartamento::orderBy('nombre')->get(['id', 'nombre', 'codigo']);

        return response()->json([
            'success'       => true,
            'data'          => $asuetos,
            'departamentos' => $departamentos,
        ]);
    }

    /**
     * GET /api/rrhh/asuetos/rango
     * Asuetos que aplican a una sucursal en un rango de fechas.
     * Usado por el cálculo de planilla.
     * Params: sucursal_id, fecha_inicio, fecha_fin
     */
    public function porSucursalRango(Request $request): JsonResponse
    {
        $v = $request->validate([
            'sucursal_id'  => 'required|integer',
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
        ]);

        $geoDptoId = DB::table('sucursales')
            ->where('id', $v['sucursal_id'])
            ->value('geo_departamento_id');

        $asuetos = Asueto::where('activo', true)
            ->whereBetween('fecha', [$v['fecha_inicio'], $v['fecha_fin']])
            ->where(function ($q) use ($geoDptoId) {
                $q->where('tipo', 'nacional');
                if ($geoDptoId) {
                    $q->orWhere(function ($q2) use ($geoDptoId) {
                        $q2->where('tipo', 'departamental')
                           ->where('geo_departamento_id', $geoDptoId);
                    });
                }
            })
            ->orderBy('fecha')
            ->get(['id', 'fecha', 'nombre', 'tipo', 'geo_departamento_id']);

        return response()->json(['success' => true, 'data' => $asuetos]);
    }

    /** POST /api/rrhh/asuetos */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $v = $request->validate([
                'fecha'               => 'required|date',
                'nombre'              => 'required|string|max:150',
                'tipo'                => ['required', Rule::in(['nacional', 'departamental'])],
                'geo_departamento_id' => 'nullable|integer|exists:pgsql.geo_departamentos,id',
            ]);

            if ($v['tipo'] === 'departamental' && empty($v['geo_departamento_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un asueto departamental debe tener departamento geográfico.',
                ], 422);
            }

            if ($v['tipo'] === 'nacional') {
                $v['geo_departamento_id'] = null;
            }

            $asueto = Asueto::create([
                ...$v,
                'activo'     => true,
                'creado_por' => Auth::user()->email,
            ]);

            $asueto->load('geoDepartamento:id,nombre,codigo');

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'                  => $asueto->id,
                    'fecha'               => $asueto->fecha->format('Y-m-d'),
                    'nombre'              => $asueto->nombre,
                    'tipo'                => $asueto->tipo,
                    'geo_departamento_id' => $asueto->geo_departamento_id,
                    'geo_departamento'    => $asueto->geoDepartamento?->nombre,
                    'activo'              => $asueto->activo,
                ],
            ], 201);
        });
    }

    /** PUT /api/rrhh/asuetos/{id} */
    public function update(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $asueto = Asueto::findOrFail($id);

            $v = $request->validate([
                'fecha'               => 'required|date',
                'nombre'              => 'required|string|max:150',
                'tipo'                => ['required', Rule::in(['nacional', 'departamental'])],
                'geo_departamento_id' => 'nullable|integer|exists:pgsql.geo_departamentos,id',
            ]);

            if ($v['tipo'] === 'departamental' && empty($v['geo_departamento_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Un asueto departamental debe tener departamento geográfico.',
                ], 422);
            }

            if ($v['tipo'] === 'nacional') {
                $v['geo_departamento_id'] = null;
            }

            $asueto->update($v);
            $asueto->load('geoDepartamento:id,nombre,codigo');

            return response()->json([
                'success' => true,
                'data'    => [
                    'id'                  => $asueto->id,
                    'fecha'               => $asueto->fecha->format('Y-m-d'),
                    'nombre'              => $asueto->nombre,
                    'tipo'                => $asueto->tipo,
                    'geo_departamento_id' => $asueto->geo_departamento_id,
                    'geo_departamento'    => $asueto->geoDepartamento?->nombre,
                    'activo'              => $asueto->activo,
                ],
            ]);
        });
    }

    /** DELETE /api/rrhh/asuetos/{id} */
    public function destroy(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $asueto = Asueto::findOrFail($id);
            $asueto->delete();
            return response()->json(['success' => true, 'message' => 'Asueto eliminado.']);
        });
    }
}
