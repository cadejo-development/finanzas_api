<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\ReglaAprobacion;
use Illuminate\Http\JsonResponse;

class ReglaAprobacionController extends Controller
{
    /**
     * GET /api/pagos/reglas-aprobacion?tipo_gasto=OPEX|CAPEX
     *
     * Retorna todas las reglas activas (sin filtrar por monto),
     * útil para mostrar la matriz informativa en el frontend.
     */
    public function index(): JsonResponse
    {
        $tipo = strtoupper(request('tipo_gasto', ''));

        $query = ReglaAprobacion::where('activo', true)
            ->orderBy('nivel_orden')
            ->orderBy('id');

        if ($tipo) {
            $query->where('tipo_gasto', $tipo);
        }

        $reglas = $query->get()->map(fn(ReglaAprobacion $r) => [
            'id'            => $r->id,
            'tipo_gasto'    => $r->tipo_gasto,
            'nivel_orden'   => $r->nivel_orden,
            'nivel_codigo'  => $r->nivel_codigo,
            'rol_requerido' => $r->rol_requerido,
            'etiqueta'      => $r->etiqueta,
            'monto_min'     => $r->monto_min,
            'monto_max'     => $r->monto_max,
            'es_visto_bueno'=> $r->esVistoBueno(),
            'rango'         => $r->rango,
        ]);

        return response()->json($reglas);
    }
}
