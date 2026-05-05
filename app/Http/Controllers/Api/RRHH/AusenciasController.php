<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\AusenciaInjustificada;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AusenciasController extends RRHHBaseController
{
    /**
     * GET /api/rrhh/ausencias
     * Lista ausencias injustificadas del equipo (+ alertas).
     */
    public function index(Request $request): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $query = AusenciaInjustificada::whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('fecha');

        if ($empleadoId = $request->query('empleado_id')) {
            $query->where('empleado_id', (int) $empleadoId);
        }
        if ($desde = $request->query('desde')) {
            $query->where('fecha', '>=', $desde);
        }
        if ($hasta = $request->query('hasta')) {
            $query->where('fecha', '<=', $hasta);
        }

        $ausencias = $query->get();
        $data = $this->enrichWithEmpleadoData($ausencias->toArray());

        // Calcular alertas por empleado
        $alertas = $this->calcularAlertas($ausencias->toArray());

        return response()->json([
            'success' => true,
            'data'    => $data,
            'alertas' => $alertas,
        ]);
    }

    /**
     * POST /api/rrhh/ausencias
     */
    public function store(Request $request): JsonResponse
    {

        $validated = $request->validate([
            'empleado_id' => 'required|integer',
            'fecha'       => 'required|date',
            'descripcion' => 'nullable|string|max:500',
        ]);

        $jefe = $this->getJefeEmpleado();

        if (!$this->puedeGestionar($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        // Verificar duplicado
        $existe = AusenciaInjustificada::where('empleado_id', $validated['empleado_id'])
            ->where('fecha', $validated['fecha'])
            ->exists();

        if ($existe) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe una ausencia registrada para este empleado en esa fecha.',
            ], 422);
        }

        $ausencia = AusenciaInjustificada::create([
            'empleado_id'      => $validated['empleado_id'],
            'registrado_por_id'=> $jefe->id,
            'fecha'            => $validated['fecha'],
            'descripcion'      => $validated['descripcion'] ?? null,
            'aud_usuario'      => Auth::user()->email,
        ]);

        $arr = $this->enrichWithEmpleadoData([$ausencia->toArray()]);

        // Notificar al empleado
        $this->notificarAlEmpleado(
            empleadoId:   $validated['empleado_id'],
            tipo:         'Ausencia Injustificada',
            mensaje:      "Se ha registrado una ausencia injustificada en tu expediente. Si consideras que este registro es incorrecto, comunicate con tu jefe inmediato.",
            detalles: array_filter([
                'Fecha'       => $validated['fecha'],
                'Descripcion' => $validated['descripcion'] ?? null,
            ]),
            rutaFrontend: 'mi-expediente',
        );

        return response()->json(['success' => true, 'data' => $arr[0]], 201);
    }

    /**
     * DELETE /api/rrhh/ausencias/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $ausencia = AusenciaInjustificada::findOrFail($id);

        if (!$this->puedeGestionar($ausencia->empleado_id)) {
            return response()->json(['success' => false, 'message' => 'Sin permiso.'], 403);
        }

        $ausencia->delete();
        return response()->json(['success' => true, 'message' => 'Ausencia eliminada.']);
    }

    /**
     * GET /api/rrhh/ausencias/resumen-mes
     * Devuelve alertas del mes actual para el dashboard.
     */
    public function resumenMes(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();
        $hoy   = now();
        $desde = $hoy->copy()->startOfMonth()->toDateString();
        $hasta = $hoy->toDateString();

        $ausencias = AusenciaInjustificada::whereIn('empleado_id', $subordinadosIds)
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderBy('empleado_id')
            ->orderBy('fecha')
            ->get();

        $alertas = $this->calcularAlertas($ausencias->toArray());
        $data    = $this->enrichWithEmpleadoData($ausencias->toArray());

        return response()->json([
            'success'       => true,
            'data'          => $data,
            'alertas'       => $alertas,
            'total_ausencias_mes' => count($data),
        ]);
    }

    /**
     * Calcula alertas por empleado:
     * - consecutivas >= 2 → alerta_consecutiva
     * - en el mes >= 3   → alerta_mensual
     */
    private function calcularAlertas(array $rows): array
    {
        // Agrupar por empleado
        $porEmpleado = [];
        foreach ($rows as $r) {
            $porEmpleado[$r['empleado_id']][] = $r['fecha'];
        }

        $alertas = [];
        foreach ($porEmpleado as $empId => $fechas) {
            sort($fechas);

            $maxConsecutivas = 1;
            $actual = 1;
            for ($i = 1; $i < count($fechas); $i++) {
                $diff = (new \DateTime($fechas[$i]))->diff(new \DateTime($fechas[$i - 1]))->days;
                if ($diff === 1) {
                    $actual++;
                    $maxConsecutivas = max($maxConsecutivas, $actual);
                } else {
                    $actual = 1;
                }
            }

            $alertaConsecutiva = $maxConsecutivas >= 2;
            $alertaMensual     = count($fechas) >= 3;

            if ($alertaConsecutiva || $alertaMensual) {
                $alertas[] = [
                    'empleado_id'        => $empId,
                    'total_ausencias'    => count($fechas),
                    'max_consecutivas'   => $maxConsecutivas,
                    'alerta_consecutiva' => $alertaConsecutiva,
                    'alerta_mensual'     => $alertaMensual,
                ];
            }
        }

        return $alertas;
    }
}
