<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\SaldoVacaciones;
use App\Models\RRHH\Vacacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VacacionesController extends RRHHBaseController
{
    /**
     * GET /api/rrhh/vacaciones
     */
    public function index(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $vacaciones = Vacacion::whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('id')
            ->get();

        $data = $this->enrichWithEmpleadoData($vacaciones->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/vacaciones
     */
    public function store(Request $request): JsonResponse
    {
        $jefe = $this->getJefeEmpleado();

        $validated = $request->validate([
            'empleado_id'  => 'required|integer',
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
            'dias'         => 'required|numeric|min:0.5',
            'observaciones'=> 'nullable|string|max:500',
        ]);

        if (!$this->esSubordinado($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        $vacacion = Vacacion::create(array_merge($validated, [
            'jefe_id'     => $jefe->id,
            'estado'      => 'pendiente',
            'aud_usuario' => Auth::user()->email,
        ]));

        return response()->json(['success' => true, 'data' => $vacacion], 201);
    }

    /**
     * GET /api/rrhh/vacaciones/{id}
     */
    public function show(int $id): JsonResponse
    {
        $vacacion = Vacacion::findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$vacacion->toArray()]);

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * PUT /api/rrhh/vacaciones/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $vacacion = Vacacion::findOrFail($id);

        $validated = $request->validate([
            'fecha_inicio'  => 'sometimes|date',
            'fecha_fin'     => 'sometimes|date|after_or_equal:fecha_inicio',
            'dias'          => 'sometimes|numeric|min:0.5',
            'estado'        => 'sometimes|in:pendiente,aprobado,rechazado',
            'observaciones' => 'nullable|string|max:500',
        ]);

        $vacacion->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));

        // Si se aprueba, descontar del saldo
        if (($validated['estado'] ?? null) === 'aprobado') {
            $this->descontarSaldo($vacacion->empleado_id, $vacacion->dias);
        }

        return response()->json(['success' => true, 'data' => $vacacion]);
    }

    /**
     * DELETE /api/rrhh/vacaciones/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        Vacacion::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Vacación eliminada.']);
    }

    /**
     * Saldo de vacaciones del equipo para el año actual.
     * GET /api/rrhh/vacaciones/saldos
     */
    public function saldos(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();
        $anio = now()->year;

        $saldos = SaldoVacaciones::whereIn('empleado_id', $subordinadosIds)
            ->where('anio', $anio)
            ->get()
            ->keyBy('empleado_id');

        // Para empleados sin saldo registrado, mostrar valores por defecto
        $data = collect($subordinadosIds)->map(function ($empId) use ($saldos, $anio) {
            $saldo = $saldos[$empId] ?? null;
            return [
                'empleado_id'      => $empId,
                'anio'             => $anio,
                'dias_disponibles' => $saldo?->dias_disponibles ?? 15,
                'dias_usados'      => $saldo?->dias_usados      ?? 0,
                'dias_acumulados'  => $saldo?->dias_acumulados  ?? 0,
                'dias_totales'     => ($saldo?->dias_disponibles ?? 15) + ($saldo?->dias_acumulados ?? 0),
            ];
        });

        $result = $this->enrichWithEmpleadoData($data->all());

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function descontarSaldo(int $empleadoId, float $dias): void
    {
        $anio = now()->year;
        $saldo = SaldoVacaciones::firstOrCreate(
            ['empleado_id' => $empleadoId, 'anio' => $anio],
            ['dias_disponibles' => 15, 'dias_usados' => 0, 'dias_acumulados' => 0]
        );
        $saldo->increment('dias_usados', $dias);
    }
}
