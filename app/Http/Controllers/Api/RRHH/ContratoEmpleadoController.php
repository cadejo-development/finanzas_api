<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\ContratoEmpleado;
use App\Models\RRHH\IngresoPersonal;
use App\Models\RRHH\PlantillaContrato;
use App\Models\RRHH\TipoContrato;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class ContratoEmpleadoController extends RRHHBaseController
{
    /** GET /api/rrhh/ingresos/{ingresoId}/contratos */
    public function porIngreso(int $ingresoId): JsonResponse
    {
        $ingreso = IngresoPersonal::findOrFail($ingresoId);

        $contratos = ContratoEmpleado::with(['tipoContrato'])
            ->where('ingreso_id', $ingresoId)
            ->orderByDesc('fecha_inicio')
            ->get();

        return response()->json(['success' => true, 'data' => $contratos]);
    }

    /** POST /api/rrhh/ingresos/{ingresoId}/contratos */
    public function store(Request $request, int $ingresoId): JsonResponse
    {
        $ingreso = IngresoPersonal::findOrFail($ingresoId);

        $v = $request->validate([
            'tipo_contrato_id' => 'required|integer|exists:rrhh.tipos_contrato,id',
            'plantilla_id'     => 'nullable|integer|exists:rrhh.plantillas_contrato,id',
            'fecha_inicio'     => 'required|date',
            'fecha_fin'        => 'nullable|date|after:fecha_inicio',
            'notas'            => 'nullable|string|max:2000',
        ]);

        $contrato = ContratoEmpleado::create([
            ...$v,
            'empleado_id'      => $ingreso->empleado_id,
            'empleado_nombre'  => $ingreso->empleado_nombre,
            'ingreso_id'       => $ingresoId,
            'estado'           => 'activo',
            'generado_por_id'  => Auth::id(),
            'aud_usuario'      => Auth::user()->email,
        ]);

        $contrato->load('tipoContrato');

        return response()->json(['success' => true, 'data' => $contrato], 201);
    }

    /** PATCH /api/rrhh/contratos/{id}/estado */
    public function actualizarEstado(Request $request, int $id): JsonResponse
    {
        $contrato = ContratoEmpleado::findOrFail($id);

        $v = $request->validate([
            'estado' => 'required|in:' . implode(',', ContratoEmpleado::ESTADOS),
            'notas'  => 'nullable|string|max:2000',
        ]);

        $contrato->update([...$v, 'aud_usuario' => Auth::user()->email]);

        return response()->json(['success' => true, 'data' => $contrato]);
    }

    /**
     * GET /api/rrhh/contratos/{id}/preview
     * Devuelve el contenido de la plantilla con las variables sustituidas.
     */
    public function preview(int $id): JsonResponse
    {
        $contrato = ContratoEmpleado::with(['tipoContrato', 'plantilla', 'ingreso'])->findOrFail($id);

        if (!$contrato->plantilla) {
            return response()->json([
                'success' => false,
                'message' => 'Este contrato no tiene una plantilla asociada.',
            ], 422);
        }

        $contenido = $this->renderPlantilla($contrato->plantilla->contenido, $contrato);

        return response()->json(['success' => true, 'contenido' => $contenido]);
    }

    private function renderPlantilla(string $contenido, ContratoEmpleado $contrato): string
    {
        $ingreso = $contrato->ingreso;
        $vars = [
            '{{nombre}}'           => $contrato->empleado_nombre,
            '{{cargo}}'            => $ingreso?->cargo_nombre ?? '',
            '{{sucursal}}'         => $ingreso?->sucursal_nombre ?? '',
            '{{fecha_inicio}}'     => $contrato->fecha_inicio?->format('d/m/Y') ?? '',
            '{{fecha_fin}}'        => $contrato->fecha_fin?->format('d/m/Y') ?? '',
            '{{tipo_contrato}}'    => $contrato->tipoContrato?->nombre ?? '',
        ];

        return str_replace(array_keys($vars), array_values($vars), $contenido);
    }
}
