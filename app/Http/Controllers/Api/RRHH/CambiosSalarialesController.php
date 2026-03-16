<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\CambioSalarial;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class CambiosSalarialesController extends RRHHBaseController
{
    /**
     * GET /api/rrhh/cambios-salariales
     * Acepta ?tipo_aumento_id=X para filtrar por tipo
     */
    public function index(Request $request): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $query = CambioSalarial::with('tipoAumento')
            ->whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('id');

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
        $jefe = $this->getJefeEmpleado();

        $validated = $request->validate([
            'empleado_id'     => 'required|integer',
            'tipo_aumento_id' => 'required|exists:rrhh.tipos_aumento_salarial,id',
            'salario_anterior'=> 'required|numeric|min:0',
            'salario_nuevo'   => 'required|numeric|min:0|gt:salario_anterior',
            'fecha_efectiva'  => 'required|date',
            'justificacion'   => 'nullable|string|max:1000',
        ]);

        if (!$this->esSubordinado($validated['empleado_id'])) {
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
            'solicitado_por_id' => $jefe->id,
            'porcentaje'        => $porcentaje,
            'estado'            => 'pendiente',
            'aud_usuario'       => Auth::user()->email,
        ]));

        $cambio->load('tipoAumento');

        return response()->json(['success' => true, 'data' => $cambio], 201);
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
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $cambio = CambioSalarial::findOrFail($id);

        $validated = $request->validate([
            'tipo_aumento_id' => 'sometimes|exists:rrhh.tipos_aumento_salarial,id',
            'salario_anterior'=> 'sometimes|numeric|min:0',
            'salario_nuevo'   => 'sometimes|numeric|min:0',
            'fecha_efectiva'  => 'sometimes|date',
            'justificacion'   => 'nullable|string|max:1000',
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

        return response()->json(['success' => true, 'data' => $cambio]);
    }

    /**
     * DELETE /api/rrhh/cambios-salariales/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        CambioSalarial::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Cambio salarial eliminado.']);
    }
}
