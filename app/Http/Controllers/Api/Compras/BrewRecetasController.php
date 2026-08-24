<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\BrewReceta;
use App\Models\BrewRecetaDiaObjetivo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrewRecetasController extends Controller
{
    public function index(Request $request)
    {
        $q = BrewReceta::query();
        if ($request->filled('activa')) {
            $q->where('activa', filter_var($request->activa, FILTER_VALIDATE_BOOLEAN));
        }
        if ($request->filled('q')) {
            $q->where(function ($sq) use ($request) {
                $sq->whereRaw('LOWER(nombre) LIKE ?', ['%' . strtolower($request->q) . '%'])
                   ->orWhereRaw('LOWER(estilo) LIKE ?', ['%' . strtolower($request->q) . '%'])
                   ->orWhereRaw('LOWER(codigo) LIKE ?', ['%' . strtolower($request->q) . '%']);
            });
        }
        return $q->withCount('lotes')->orderBy('nombre')->get();
    }

    public function show($id)
    {
        $receta = BrewReceta::with([
            'maltas', 'lupulos', 'minerales', 'levaduras',
            'maceradoPasos', 'boilPasos', 'diasObjetivo',
        ])->findOrFail($id);

        $data       = $receta->toArray();
        $volPreboil = (float) ($receta->vol_preboil ?? 0);

        // rendimiento_por_litro = cantidad_ingrediente / vol_preboil_receta (para Brilo)
        if ($volPreboil > 0) {
            $data['maltas'] = collect($data['maltas'])->map(function ($m) use ($volPreboil) {
                $m['rendimiento_por_litro'] = isset($m['cantidad_lb'])
                    ? round((float) $m['cantidad_lb'] / $volPreboil, 4) : null;
                return $m;
            })->all();

            $data['lupulos'] = collect($data['lupulos'])->map(function ($l) use ($volPreboil) {
                $l['rendimiento_por_litro'] = isset($l['cantidad_g'])
                    ? round((float) $l['cantidad_g'] / $volPreboil, 4) : null;
                return $l;
            })->all();

            $data['minerales'] = collect($data['minerales'])->map(function ($m) use ($volPreboil) {
                $m['rendimiento_por_litro'] = isset($m['cantidad_g'])
                    ? round((float) $m['cantidad_g'] / $volPreboil, 4) : null;
                return $m;
            })->all();

            $data['levaduras'] = collect($data['levaduras'])->map(function ($l) use ($volPreboil) {
                $l['rendimiento_por_litro'] = isset($l['cantidad_g'])
                    ? round((float) $l['cantidad_g'] / $volPreboil, 4) : null;
                return $l;
            })->all();
        }

        return $data;
    }

    // GET /api/compras/brew/recetas/{id}/dias-objetivo
    public function diasObjetivo($id)
    {
        $receta = BrewReceta::findOrFail($id);
        return response()->json(['ok' => true, 'data' => $receta->diasObjetivo()->get()]);
    }

    // PUT /api/compras/brew/recetas/{id}/dias-objetivo
    public function guardarDiasObjetivo(Request $request, $id)
    {
        $receta = BrewReceta::findOrFail($id);
        $dias   = $request->validate([
            'dias'                  => 'required|array',
            'dias.*.dia'            => 'required|integer|min:1|max:120',
            'dias.*.etapa'          => 'required|in:fermentacion,maduracion',
            'dias.*.plato_obj'      => 'nullable|numeric',
            'dias.*.temp_obj'       => 'nullable|numeric',
            'dias.*.ph_obj'         => 'nullable|numeric',
            'dias.*.notas_objetivo' => 'nullable|string|max:500',
        ])['dias'];

        $receta->diasObjetivo()->delete();
        foreach ($dias as $dia) {
            if (!empty($dia['plato_obj']) || !empty($dia['temp_obj']) || !empty($dia['ph_obj']) || !empty($dia['notas_objetivo'])) {
                $receta->diasObjetivo()->create($dia);
            }
        }

        return response()->json(['ok' => true, 'data' => $receta->diasObjetivo()->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'              => 'required|string|max:200',
            'estilo'              => 'nullable|string|max:100',
            'brewer'              => 'nullable|string|max:100',
            'version'             => 'nullable|string|max:20',
            'fecha_receta'        => 'nullable|date',
            'vol_preboil'         => 'nullable|numeric|min:0',
            'vol_postboil'        => 'nullable|numeric|min:0',
            'vol_bbt'             => 'nullable|numeric|min:0',
            'og'                  => 'nullable|numeric',
            'fg'                  => 'nullable|numeric',
            'abv'                 => 'nullable|numeric',
            'ibu'                 => 'nullable|numeric',
            'srm'                 => 'nullable|numeric',
            'eficiencia_macerado' => 'nullable|numeric',
            'dias_ferm'           => 'nullable|integer|min:1|max:90',
            'temp_maduracion'     => 'nullable|numeric',
            'dias_maduracion'     => 'nullable|integer|min:1|max:90',
            'dias_seguimiento'    => 'nullable|integer|min:1|max:60',
            'notas'               => 'nullable|string',
            'activa'              => 'boolean',
            'maltas'              => 'array',
            'lupulos'             => 'array',
            'minerales'           => 'array',
            'levaduras'           => 'array',
            'macerado_pasos'           => 'array',
            'boil_pasos'               => 'array',
            'boil_pasos.*.descripcion'       => 'required|string',
            'boil_pasos.*.tiempo_min'        => 'nullable|integer|min:0',
            'boil_pasos.*.fase'              => 'nullable|string|in:hervor,whirlpool',
            'boil_pasos.*.cantidad_objetivo' => 'nullable|numeric|min:0',
            'boil_pasos.*.unidad'            => 'nullable|string|max:20',
            'boil_pasos.*.plato_objetivo'    => 'nullable|numeric',
            'boil_pasos.*.vol_objetivo_l'    => 'nullable|numeric|min:0',
        ]);

        DB::connection('compras')->transaction(function () use ($data, &$receta) {
            $receta = BrewReceta::create(collect($data)->except([
                'maltas', 'lupulos', 'minerales', 'levaduras', 'macerado_pasos', 'boil_pasos',
            ])->toArray());

            $this->syncChildren($receta, $data);
        });

        return $receta->load(['maltas', 'lupulos', 'minerales', 'levaduras', 'maceradoPasos', 'boilPasos']);
    }

    public function update(Request $request, $id)
    {
        $receta = BrewReceta::findOrFail($id);

        $data = $request->validate([
            'nombre'              => 'sometimes|required|string|max:200',
            'estilo'              => 'nullable|string|max:100',
            'brewer'              => 'nullable|string|max:100',
            'version'             => 'nullable|string|max:20',
            'fecha_receta'        => 'nullable|date',
            'vol_preboil'         => 'nullable|numeric|min:0',
            'vol_postboil'        => 'nullable|numeric|min:0',
            'vol_bbt'             => 'nullable|numeric|min:0',
            'og'                  => 'nullable|numeric',
            'fg'                  => 'nullable|numeric',
            'abv'                 => 'nullable|numeric',
            'ibu'                 => 'nullable|numeric',
            'srm'                 => 'nullable|numeric',
            'eficiencia_macerado' => 'nullable|numeric',
            'dias_ferm'           => 'nullable|integer|min:1|max:90',
            'temp_maduracion'     => 'nullable|numeric',
            'dias_maduracion'     => 'nullable|integer|min:1|max:90',
            'dias_seguimiento'    => 'nullable|integer|min:1|max:60',
            'notas'               => 'nullable|string',
            'activa'              => 'boolean',
            'maltas'              => 'array',
            'lupulos'             => 'array',
            'minerales'           => 'array',
            'levaduras'           => 'array',
            'macerado_pasos'           => 'array',
            'boil_pasos'               => 'array',
            'boil_pasos.*.descripcion'       => 'required|string',
            'boil_pasos.*.tiempo_min'        => 'nullable|integer|min:0',
            'boil_pasos.*.fase'              => 'nullable|string|in:hervor,whirlpool',
            'boil_pasos.*.cantidad_objetivo' => 'nullable|numeric|min:0',
            'boil_pasos.*.unidad'            => 'nullable|string|max:20',
            'boil_pasos.*.plato_objetivo'    => 'nullable|numeric',
            'boil_pasos.*.vol_objetivo_l'    => 'nullable|numeric|min:0',
        ]);

        DB::connection('compras')->transaction(function () use ($data, $receta) {
            $receta->update(collect($data)->except([
                'maltas', 'lupulos', 'minerales', 'levaduras', 'macerado_pasos', 'boil_pasos',
            ])->toArray());

            if (array_key_exists('maltas', $data) || array_key_exists('lupulos', $data) ||
                array_key_exists('minerales', $data) || array_key_exists('levaduras', $data) ||
                array_key_exists('macerado_pasos', $data) || array_key_exists('boil_pasos', $data)) {
                $this->syncChildren($receta, $data);
            }
        });

        return $receta->load(['maltas', 'lupulos', 'minerales', 'levaduras', 'maceradoPasos', 'boilPasos']);
    }

    public function destroy($id)
    {
        $receta = BrewReceta::findOrFail($id);
        if ($receta->lotes()->exists()) {
            return response()->json(['message' => 'No se puede eliminar: tiene lotes asociados'], 422);
        }
        $receta->delete();
        return response()->json(['ok' => true]);
    }

    private function syncChildren(BrewReceta $receta, array $data): void
    {
        if (isset($data['maltas'])) {
            $receta->maltas()->delete();
            foreach ($data['maltas'] as $i => $row) {
                $receta->maltas()->create(array_merge($row, ['orden' => $i]));
            }
        }
        if (isset($data['lupulos'])) {
            $receta->lupulos()->delete();
            foreach ($data['lupulos'] as $i => $row) {
                $receta->lupulos()->create(array_merge($row, ['orden' => $i]));
            }
        }
        if (isset($data['minerales'])) {
            $receta->minerales()->delete();
            foreach ($data['minerales'] as $i => $row) {
                $receta->minerales()->create(array_merge($row, ['orden' => $i]));
            }
        }
        if (isset($data['levaduras'])) {
            $receta->levaduras()->delete();
            foreach ($data['levaduras'] as $row) {
                $receta->levaduras()->create($row);
            }
        }
        if (isset($data['macerado_pasos'])) {
            $receta->maceradoPasos()->delete();
            foreach ($data['macerado_pasos'] as $i => $row) {
                $receta->maceradoPasos()->create(array_merge($row, ['orden' => $i]));
            }
        }
        if (isset($data['boil_pasos'])) {
            $receta->boilPasos()->delete();
            foreach ($data['boil_pasos'] as $i => $row) {
                $receta->boilPasos()->create(array_merge($row, ['orden' => $i]));
            }
        }
    }
}
