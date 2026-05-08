<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\BrewReceta;
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
            'maceradoPasos', 'boilPasos',
        ])->findOrFail($id);
        return $receta;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'              => 'required|string|max:200',
            'estilo'              => 'nullable|string|max:100',
            'codigo'              => 'nullable|string|max:30|unique:compras.brew_recetas,codigo',
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
            'notas'               => 'nullable|string',
            'activa'              => 'boolean',
            'maltas'              => 'array',
            'lupulos'             => 'array',
            'minerales'           => 'array',
            'levaduras'           => 'array',
            'macerado_pasos'      => 'array',
            'boil_pasos'          => 'array',
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
            'codigo'              => 'nullable|string|max:30|unique:compras.brew_recetas,codigo,' . $id,
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
            'notas'               => 'nullable|string',
            'activa'              => 'boolean',
            'maltas'              => 'array',
            'lupulos'             => 'array',
            'minerales'           => 'array',
            'levaduras'           => 'array',
            'macerado_pasos'      => 'array',
            'boil_pasos'          => 'array',
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
