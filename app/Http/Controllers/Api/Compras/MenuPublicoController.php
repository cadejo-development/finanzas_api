<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Receta;
use App\Models\Sucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MenuPublicoController extends Controller
{
    private const SLUG_MAP = [
        'mansion'  => 'SUC-GU',
        'huizucar' => 'SUC-HZ',
    ];

    private const IVA = 1.13;

    /**
     * GET /api/public/menu?sucursal=mansion
     * Retorna platos activos de la sucursal, agrupados por categoría.
     * Sin autenticación — solo datos públicos (nombre, categoría, precio, foto).
     */
    public function porSucursal(Request $request): JsonResponse
    {
        $slug = strtolower(trim($request->query('sucursal', 'mansion')));

        if (! isset(self::SLUG_MAP[$slug])) {
            return response()->json(['error' => 'Sucursal no válida.'], 422);
        }

        $codigo   = self::SLUG_MAP[$slug];
        $sucursal = Sucursal::where('codigo', $codigo)->first();

        if (! $sucursal) {
            return response()->json([], 200);
        }

        $platos = Receta::with(['categoria'])
            ->where('activa', true)
            ->where(function ($q) {
                $q->where('tipo_receta', 'plato')
                  ->orWhereNull('tipo_receta');
            })
            ->whereRaw("lower(coalesce(tipo,'')) NOT LIKE '%sub%receta%'")
            ->whereRaw("upper(coalesce(codigo_origen,'')) NOT LIKE 'CP%'")
            ->whereHas('sucursalConfig', fn ($q) =>
                $q->where('sucursal_id', $sucursal->id)->where('activa', true)
            )
            ->where(function ($q) {
                $q->where('precio', '>', 0)->orWhereNotNull('foto_plato');
            })
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'categoria_id', 'precio', 'foto_plato']);

        $porCategoria = [];

        foreach ($platos as $plato) {
            $cat    = $plato->categoria?->nombre ?? 'Otros';
            $precio = (float) ($plato->precio ?? 0);

            $porCategoria[$cat][] = [
                'id'             => $plato->id,
                'nombre'         => $plato->nombre,
                'categoria'      => $cat,
                'precio_unitario'=> $precio > 0 ? round($precio / self::IVA, 2) : null,
                'foto_plato'     => $plato->foto_plato,
            ];
        }

        return response()->json($porCategoria);
    }
}
