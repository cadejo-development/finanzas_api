<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Permiso;
use App\Models\RRHH\SaldoVacaciones;
use App\Models\RRHH\TipoPermiso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class PermisosController extends RRHHBaseController
{
    /**
     * Lista permisos del equipo del jefe.
     * GET /api/rrhh/permisos
     */
    public function index(): JsonResponse
    {
        $subordinadosIds = $this->getEquipoIds();

        $permisos = Permiso::with('tipoPermiso')
            ->whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('id')
            ->get();

        $data = $this->enrichWithEmpleadoData($permisos->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Crea un nuevo permiso.
     * POST /api/rrhh/permisos
     */
    public function store(Request $request): JsonResponse
    {
        $jefe = $this->getJefeEmpleado();

        $validated = $request->validate([
            'empleado_id'       => 'required|integer',
            'tipo_permiso_id'   => 'required|exists:rrhh.tipos_permiso,id',
            'fecha'             => 'required|date',
            'es_dia_completo'   => 'boolean',
            'hora_inicio'       => 'nullable|date_format:H:i|required_if:es_dia_completo,false',
            'hora_fin'          => 'nullable|date_format:H:i|required_if:es_dia_completo,false|after:hora_inicio',
            'dias'              => 'nullable|numeric|min:0.5|required_if:es_dia_completo,true',
            'motivo'            => 'nullable|string|max:500',
            'observaciones_jefe'=> 'nullable|string|max:500',
        ]);

        if (!$this->esDelEquipo($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        // Calcular horas si es parcial
        if (!($validated['es_dia_completo'] ?? true)) {
            $inicio = \Carbon\Carbon::createFromFormat('H:i', $validated['hora_inicio']);
            $fin    = \Carbon\Carbon::createFromFormat('H:i', $validated['hora_fin']);
            $validated['horas_solicitadas'] = round($inicio->floatDiffInHours($fin), 2);
            $validated['dias'] = null;
        } else {
            $validated['hora_inicio'] = null;
            $validated['hora_fin']    = null;
            $validated['horas_solicitadas'] = null;
        }

        $permiso = Permiso::create(array_merge($validated, [
            'jefe_id'     => $jefe->id,
            'estado'      => 'pendiente',
            'aud_usuario' => Auth::user()->email,
        ]));

        $permiso->load('tipoPermiso');

        return response()->json(['success' => true, 'data' => $permiso], 201);
    }

    /**
     * GET /api/rrhh/permisos/{id}
     */
    public function show(int $id): JsonResponse
    {
        $permiso = Permiso::with('tipoPermiso')->findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$permiso->toArray()]);

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * PUT /api/rrhh/permisos/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $permiso = Permiso::findOrFail($id);

        $validated = $request->validate([
            'tipo_permiso_id'    => 'sometimes|exists:rrhh.tipos_permiso,id',
            'fecha'              => 'sometimes|date',
            'es_dia_completo'    => 'sometimes|boolean',
            'hora_inicio'        => 'nullable|date_format:H:i',
            'hora_fin'           => 'nullable|date_format:H:i|after:hora_inicio',
            'dias'               => 'nullable|numeric|min:0.5',
            'motivo'             => 'nullable|string|max:500',
            'estado'             => 'sometimes|in:pendiente,aprobado,rechazado',
            'observaciones_jefe' => 'nullable|string|max:500',
        ]);

        // Recalcular horas si cambia a parcial
        if (isset($validated['es_dia_completo']) && !$validated['es_dia_completo']) {
            $inicio = $validated['hora_inicio'] ?? $permiso->hora_inicio;
            $fin    = $validated['hora_fin']    ?? $permiso->hora_fin;
            if ($inicio && $fin) {
                $validated['horas_solicitadas'] = round(
                    \Carbon\Carbon::createFromFormat('H:i', $inicio)
                        ->floatDiffInHours(\Carbon\Carbon::createFromFormat('H:i', $fin)),
                    2
                );
                $validated['dias'] = null;
            }
        }

        $permiso->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));
        $permiso->load('tipoPermiso');

        return response()->json(['success' => true, 'data' => $permiso]);
    }

    /**
     * DELETE /api/rrhh/permisos/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        Permiso::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Permiso eliminado.']);
    }

    /**
     * Saldo de permisos personales del equipo para el año actual.
     * GET /api/rrhh/permisos/saldos
     */
    public function saldos(): JsonResponse
    {
        $subordinadosIds = $this->getEquipoIds();
        $anio = now()->year;

        // Tipo permiso personal
        $tipoPersonal = TipoPermiso::where('codigo', 'PERSONAL')->first();

        $saldos = collect($subordinadosIds)->map(function ($empId) use ($anio, $tipoPersonal) {
            $diasUsados = Permiso::where('empleado_id', $empId)
                ->where('tipo_permiso_id', $tipoPersonal?->id)
                ->whereYear('fecha', $anio)
                ->whereIn('estado', ['pendiente', 'aprobado'])
                ->sum('dias');

            $horasUsadas = Permiso::where('empleado_id', $empId)
                ->where('tipo_permiso_id', $tipoPersonal?->id)
                ->whereYear('fecha', $anio)
                ->whereIn('estado', ['pendiente', 'aprobado'])
                ->sum('horas_solicitadas');

            return [
                'empleado_id'  => $empId,
                'anio'         => $anio,
                'max_dias'     => $tipoPersonal?->max_dias ?? 5,
                'dias_usados'  => (float) $diasUsados,
                'horas_usadas' => (float) $horasUsadas,
            ];
        });

        $data = $this->enrichWithEmpleadoData($saldos->all());

        return response()->json(['success' => true, 'data' => $data]);
    }
}
