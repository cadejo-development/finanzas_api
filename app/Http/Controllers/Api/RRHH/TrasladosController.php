<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Traslado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TrasladosController extends RRHHBaseController
{
    /**
     * GET /api/rrhh/traslados
     */
    public function index(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $traslados = Traslado::whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('id')
            ->get();

        $data = $this->enrichWithEmpleadoData($traslados->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/traslados
     */
    public function store(Request $request): JsonResponse
    {
        $jefe = $this->getJefeEmpleado();

        $validated = $request->validate([
            'empleado_id'              => 'required|integer',
            'sucursal_destino_id'      => 'required|integer',
            'cargo_destino_id'         => 'nullable|integer',
            'departamento_destino_id'  => 'nullable|integer',
            'fecha_efectiva'           => 'required|date',
            'motivo'                   => 'nullable|string|max:500',
        ]);

        if (!$this->esSubordinado($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        // Capturar datos actuales del empleado (origen)
        $empData = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c',        'e.cargo_id',        '=', 'c.id')
            ->leftJoin('sucursales as s',     'e.sucursal_id',     '=', 's.id')
            ->leftJoin('departamentos as d',  'e.departamento_id', '=', 'd.id')
            ->where('e.id', $validated['empleado_id'])
            ->select(
                'e.sucursal_id', 's.nombre as sucursal_nombre',
                'e.cargo_id',    'c.nombre as cargo_nombre',
                'e.departamento_id', 'd.nombre as departamento_nombre'
            )
            ->first();

        // Nombres de destino
        $sucursalDestino = DB::connection('pgsql')
            ->table('sucursales')
            ->where('id', $validated['sucursal_destino_id'])
            ->value('nombre');

        $cargoDestino = null;
        if (!empty($validated['cargo_destino_id'])) {
            $cargoDestino = DB::connection('pgsql')
                ->table('cargos')
                ->where('id', $validated['cargo_destino_id'])
                ->value('nombre');
        }

        $deptDestino = null;
        if (!empty($validated['departamento_destino_id'])) {
            $deptDestino = DB::connection('pgsql')
                ->table('departamentos')
                ->where('id', $validated['departamento_destino_id'])
                ->value('nombre');
        }

        $estado = $this->estadoParaEmpleado($validated['empleado_id']);

        $traslado = Traslado::create([
            'empleado_id'                => $validated['empleado_id'],
            'solicitado_por_id'          => $jefe->id,
            'sucursal_origen_id'         => $empData?->sucursal_id,
            'sucursal_origen_nombre'     => $empData?->sucursal_nombre,
            'cargo_origen_id'            => $empData?->cargo_id,
            'cargo_origen_nombre'        => $empData?->cargo_nombre,
            'departamento_origen_id'     => $empData?->departamento_id,
            'departamento_origen_nombre' => $empData?->departamento_nombre,
            'sucursal_destino_id'        => $validated['sucursal_destino_id'],
            'sucursal_destino_nombre'    => $sucursalDestino,
            'cargo_destino_id'           => $validated['cargo_destino_id']    ?? null,
            'cargo_destino_nombre'       => $cargoDestino,
            'departamento_destino_id'    => $validated['departamento_destino_id'] ?? null,
            'departamento_destino_nombre'=> $deptDestino,
            'fecha_efectiva'             => $validated['fecha_efectiva'],
            'motivo'                     => $validated['motivo'] ?? null,
            'estado'                     => $estado,
            'aud_usuario'                => Auth::user()->email,
        ]);

        // Si ya fue aprobado y la fecha efectiva ya llegó → ejecutar traslado
        if ($estado === 'aprobado' && $traslado->fecha_efectiva->lte(now()->startOfDay())) {
            $this->ejecutarTraslado($traslado);
        }

        return response()->json(['success' => true, 'data' => $traslado], 201);
    }

    /**
     * GET /api/rrhh/traslados/{id}
     */
    public function show(int $id): JsonResponse
    {
        $traslado = Traslado::findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$traslado->toArray()]);

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * PUT /api/rrhh/traslados/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $traslado = Traslado::findOrFail($id);

        $validated = $request->validate([
            'sucursal_destino_id'     => 'sometimes|integer',
            'cargo_destino_id'        => 'nullable|integer',
            'departamento_destino_id' => 'nullable|integer',
            'fecha_efectiva'          => 'sometimes|date',
            'motivo'                  => 'nullable|string|max:500',
            'estado'                  => 'sometimes|in:pendiente,aprobado,rechazado,completado',
        ]);

        // Actualizar nombres denormalizados si cambia sucursal destino
        if (isset($validated['sucursal_destino_id'])) {
            $validated['sucursal_destino_nombre'] = DB::connection('pgsql')
                ->table('sucursales')
                ->where('id', $validated['sucursal_destino_id'])
                ->value('nombre');
        }

        if (!empty($validated['cargo_destino_id'])) {
            $validated['cargo_destino_nombre'] = DB::connection('pgsql')
                ->table('cargos')
                ->where('id', $validated['cargo_destino_id'])
                ->value('nombre');
        }

        if (!empty($validated['departamento_destino_id'])) {
            $validated['departamento_destino_nombre'] = DB::connection('pgsql')
                ->table('departamentos')
                ->where('id', $validated['departamento_destino_id'])
                ->value('nombre');
        }

        $traslado->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));

        // Si el estado cambia a aprobado y la fecha efectiva ya llegó → ejecutar traslado
        if (($validated['estado'] ?? null) === 'aprobado'
            && $traslado->fresh()->fecha_efectiva->lte(now()->startOfDay())) {
            $this->ejecutarTraslado($traslado->fresh());
        }

        return response()->json(['success' => true, 'data' => $traslado->fresh()]);
    }

    /**
     * DELETE /api/rrhh/traslados/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        Traslado::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Traslado eliminado.']);
    }

    /**
     * Ejecuta el traslado: actualiza pgsql.empleados y marca como completado.
     */
    private function ejecutarTraslado(Traslado $traslado): void
    {
        $updates = ['sucursal_id' => $traslado->sucursal_destino_id];

        if ($traslado->cargo_destino_id) {
            $updates['cargo_id'] = $traslado->cargo_destino_id;
        }

        if ($traslado->departamento_destino_id) {
            $updates['departamento_id'] = $traslado->departamento_destino_id;
        } elseif ($traslado->sucursal_destino_id !== $traslado->sucursal_origen_id) {
            // Al cambiar de sucursal sin especificar departamento destino,
            // limpiar el departamento (puede no aplica en destino)
            $updates['departamento_id'] = null;
        }

        DB::connection('pgsql')
            ->table('empleados')
            ->where('id', $traslado->empleado_id)
            ->update($updates);

        $traslado->update(['estado' => 'completado', 'aud_usuario' => Auth::user()->email]);
    }
}

