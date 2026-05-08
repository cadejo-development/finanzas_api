<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\BrewLote;
use App\Models\BrewReceta;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrewLotesController extends Controller
{
    public function index(Request $request)
    {
        $q = BrewLote::with('receta:id,nombre,estilo,codigo');
        if ($request->filled('estado')) {
            $q->where('estado', $request->estado);
        }
        if ($request->filled('receta_id')) {
            $q->where('brew_receta_id', $request->receta_id);
        }
        if ($request->filled('q')) {
            $q->where(function ($sq) use ($request) {
                $sq->whereRaw('LOWER(codigo_lote) LIKE ?', ['%' . strtolower($request->q) . '%'])
                   ->orWhereRaw('LOWER(cervecero) LIKE ?', ['%' . strtolower($request->q) . '%']);
            });
        }
        return $q->orderBy('fecha_coccion', 'desc')->get();
    }

    public function show($id)
    {
        $lote = BrewLote::with([
            'receta.maltas', 'receta.lupulos', 'receta.minerales',
            'receta.levaduras', 'receta.maceradoPasos', 'receta.boilPasos',
            'coccion', 'maceradoPasos', 'boilPasos',
            'filtracion', 'filtracionCorridas',
            'fermentacion', 'fermSeguimiento',
            'llenadoBotellas', 'llenadoBarriles',
        ])->findOrFail($id);

        return array_merge($lote->toArray(), ['reporte' => $this->calcularReporte($lote)]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'brew_receta_id' => 'required|exists:compras.brew_recetas,id',
            'codigo_lote'    => 'required|string|max:30|unique:compras.brew_lotes,codigo_lote',
            'fecha_coccion'  => 'required|date',
            'cervecero'      => 'nullable|string|max:100',
            'notas'          => 'nullable|string',
        ]);

        DB::connection('compras')->transaction(function () use ($data, &$lote) {
            $lote = BrewLote::create(array_merge($data, ['estado' => 'coccion']));
            // Copiar pasos de macerado y boil desde la receta
            $receta = BrewReceta::with(['maceradoPasos', 'boilPasos'])->find($data['brew_receta_id']);
            foreach ($receta->maceradoPasos as $paso) {
                $lote->maceradoPasos()->create([
                    'orden' => $paso->orden, 'nombre' => $paso->nombre,
                    'temp_objetivo' => $paso->temp_objetivo, 'tiempo_min' => $paso->tiempo_min,
                ]);
            }
            foreach ($receta->boilPasos as $paso) {
                $lote->boilPasos()->create([
                    'orden' => $paso->orden, 'descripcion' => $paso->descripcion,
                    'tiempo_min' => $paso->tiempo_min, 'completado' => false,
                ]);
            }
        });

        return $lote->load(['receta:id,nombre,estilo,codigo']);
    }

    // ─── Etapas del wizard ───────────────────────────────────────────────────

    public function guardarCoccion(Request $request, $id)
    {
        $lote = BrewLote::findOrFail($id);
        $data = $request->validate([
            'og_real'           => 'nullable|numeric',
            'vol_preboil_real'  => 'nullable|numeric|min:0',
            'vol_postboil_real' => 'nullable|numeric|min:0',
            'temp_mash_real'    => 'nullable|numeric',
            'tiempo_boil_min'   => 'nullable|integer|min:0',
            'notas'             => 'nullable|string',
            'macerado_pasos'    => 'array',
            'boil_pasos'        => 'array',
        ]);

        DB::connection('compras')->transaction(function () use ($lote, $data) {
            $lote->coccion()->updateOrCreate(
                ['brew_lote_id' => $lote->id],
                collect($data)->except(['macerado_pasos', 'boil_pasos'])->toArray()
            );

            if (isset($data['macerado_pasos'])) {
                foreach ($data['macerado_pasos'] as $row) {
                    $lote->maceradoPasos()->where('id', $row['id'])->update([
                        'temp_real'   => $row['temp_real'] ?? null,
                        'hora_inicio' => $row['hora_inicio'] ?? null,
                        'hora_fin'    => $row['hora_fin'] ?? null,
                    ]);
                }
            }
            if (isset($data['boil_pasos'])) {
                foreach ($data['boil_pasos'] as $row) {
                    $lote->boilPasos()->where('id', $row['id'])->update([
                        'hora'        => $row['hora'] ?? null,
                        'completado'  => $row['completado'] ?? false,
                    ]);
                }
            }

            if ($lote->estado === 'coccion') {
                $lote->update(['estado' => 'filtracion']);
            }
        });

        return response()->json(['ok' => true, 'estado' => $lote->fresh()->estado]);
    }

    public function guardarFiltracion(Request $request, $id)
    {
        $lote = BrewLote::findOrFail($id);
        $data = $request->validate([
            'vol_bbt_real'  => 'nullable|numeric|min:0',
            'og_bbt'        => 'nullable|numeric',
            'temp_transfer' => 'nullable|numeric',
            'num_corridas'  => 'nullable|integer|min:1',
            'notas'         => 'nullable|string',
            'corridas'      => 'array',
        ]);

        DB::connection('compras')->transaction(function () use ($lote, $data) {
            $lote->filtracion()->updateOrCreate(
                ['brew_lote_id' => $lote->id],
                collect($data)->except('corridas')->toArray()
            );

            if (isset($data['corridas'])) {
                $lote->filtracionCorridas()->delete();
                foreach ($data['corridas'] as $i => $row) {
                    $lote->filtracionCorridas()->create(array_merge($row, ['numero_corrida' => $i + 1]));
                }
            }

            if ($lote->estado === 'filtracion') {
                $lote->update(['estado' => 'fermentacion']);
            }
        });

        return response()->json(['ok' => true]);
    }

    public function guardarFermentacion(Request $request, $id)
    {
        $lote = BrewLote::findOrFail($id);
        $data = $request->validate([
            'fecha_pitch'         => 'required|date',
            'temp_pitch'          => 'nullable|numeric',
            'og_pitch'            => 'nullable|numeric',
            'vol_pitch'           => 'nullable|numeric',
            'levadura_nombre'     => 'nullable|string|max:100',
            'levadura_cantidad_g' => 'nullable|numeric',
            'notas'               => 'nullable|string',
        ]);

        $lote->fermentacion()->updateOrCreate(['brew_lote_id' => $lote->id], $data);

        if ($lote->estado === 'fermentacion') {
            $lote->update(['estado' => 'seguimiento']);
        }

        return response()->json(['ok' => true]);
    }

    public function guardarSeguimiento(Request $request, $id)
    {
        $lote = BrewLote::findOrFail($id);
        $data = $request->validate([
            'dias' => 'required|array',
            'dias.*.dia'      => 'required|integer|min:1',
            'dias.*.fecha'    => 'required|date',
            'dias.*.gravedad' => 'nullable|numeric',
            'dias.*.temp'     => 'nullable|numeric',
            'dias.*.ph'       => 'nullable|numeric',
            'dias.*.notas'    => 'nullable|string',
        ]);

        DB::connection('compras')->transaction(function () use ($lote, $data) {
            foreach ($data['dias'] as $dia) {
                $lote->fermSeguimiento()->updateOrCreate(
                    ['brew_lote_id' => $lote->id, 'dia' => $dia['dia']],
                    $dia
                );
            }
        });

        return response()->json(['ok' => true]);
    }

    public function guardarLlenado(Request $request, $id)
    {
        $lote = BrewLote::findOrFail($id);
        $data = $request->validate([
            'botellas'                   => 'nullable|array',
            'botellas.fecha'             => 'nullable|date',
            'botellas.vol_inicio'        => 'nullable|numeric',
            'botellas.vol_fin'           => 'nullable|numeric',
            'botellas.botellas_buenas'   => 'nullable|integer',
            'botellas.botellas_rotas'    => 'nullable|integer',
            'botellas.fg_real'           => 'nullable|numeric',
            'botellas.co2_vol'           => 'nullable|numeric',
            'botellas.notas'             => 'nullable|string',
            'barriles'                   => 'nullable|array',
            'barriles.fecha'             => 'nullable|date',
            'barriles.barriles_6th'      => 'nullable|integer',
            'barriles.barriles_half'     => 'nullable|integer',
            'barriles.vol_total_barriles'=> 'nullable|numeric',
            'barriles.fg_real'           => 'nullable|numeric',
            'barriles.co2_psi'           => 'nullable|numeric',
            'barriles.notas'             => 'nullable|string',
        ]);

        DB::connection('compras')->transaction(function () use ($lote, $data) {
            if (!empty($data['botellas'])) {
                $lote->llenadoBotellas()->updateOrCreate(
                    ['brew_lote_id' => $lote->id],
                    $data['botellas']
                );
            }
            if (!empty($data['barriles'])) {
                $lote->llenadoBarriles()->updateOrCreate(
                    ['brew_lote_id' => $lote->id],
                    $data['barriles']
                );
            }
            $lote->update(['estado' => 'completo']);
        });

        return response()->json(['ok' => true]);
    }

    public function reporte($id)
    {
        $lote = BrewLote::with([
            'receta', 'coccion', 'filtracion', 'fermentacion',
            'fermSeguimiento', 'llenadoBotellas', 'llenadoBarriles',
        ])->findOrFail($id);

        return $this->calcularReporte($lote);
    }

    // ─── Cálculo de eficiencias ───────────────────────────────────────────────

    private function calcularReporte(BrewLote $lote): array
    {
        $receta   = $lote->receta;
        $coccion  = $lote->coccion;
        $filtr    = $lote->filtracion;
        $botellas = $lote->llenadoBotellas;
        $barriles = $lote->llenadoBarriles;

        // Volúmenes reales
        $volPreboil  = (float) ($coccion->vol_preboil_real ?? 0);
        $volPostboil = (float) ($coccion->vol_postboil_real ?? 0);
        $volBbt      = (float) ($filtr->vol_bbt_real ?? 0);

        // Llenado
        $volBotellas = 0;
        $volBarriles = 0;
        if ($botellas) {
            $volBotellas = ($botellas->botellas_buenas ?? 0) * 0.330;
        }
        if ($barriles) {
            $volBarriles = (($barriles->barriles_6th ?? 0) * 19.8)
                         + (($barriles->barriles_half ?? 0) * 58.7);
        }
        $volTotal = $volBotellas + $volBarriles;

        // Eficiencias (%)
        $ef_coccion   = ($volPreboil > 0 && $volPostboil > 0) ? ($volPostboil / $volPreboil * 100) : null;
        $ef_filtracion = ($volPostboil > 0 && $volBbt > 0)    ? ($volBbt / $volPostboil * 100)     : null;
        $ef_llenado    = ($volBbt > 0 && $volTotal > 0)        ? ($volTotal / $volBbt * 100)         : null;
        $ef_total      = ($volPreboil > 0 && $volTotal > 0)    ? ($volTotal / $volPreboil * 100)     : null;

        // Rendimiento botellas (botella buena / litro neto embotellado)
        $rend_botellas = null;
        if ($botellas && ($botellas->vol_inicio ?? 0) > 0 && ($botellas->vol_fin ?? 0) >= 0) {
            $litrosNetos = ($botellas->vol_inicio - $botellas->vol_fin) * 1000 / 330;
            $rend_botellas = $litrosNetos > 0
                ? round(($botellas->botellas_buenas ?? 0) / $litrosNetos * 100, 1)
                : null;
        }

        // ABV calculado desde OG/FG reales
        $og   = (float) ($lote->fermentacion->og_pitch ?? $coccion->og_real ?? 0);
        $fg   = (float) ($botellas->fg_real ?? $barriles->fg_real ?? 0);
        $abv_real = ($og > 0 && $fg > 0) ? round(($og - $fg) * 131.25, 2) : null;

        return [
            'vol_preboil'     => $volPreboil,
            'vol_postboil'    => $volPostboil,
            'vol_bbt'         => $volBbt,
            'vol_botellas'    => round($volBotellas, 2),
            'vol_barriles'    => round($volBarriles, 2),
            'vol_total'       => round($volTotal, 2),
            'ef_coccion'      => $ef_coccion    ? round($ef_coccion, 1)    : null,
            'ef_filtracion'   => $ef_filtracion ? round($ef_filtracion, 1) : null,
            'ef_llenado'      => $ef_llenado    ? round($ef_llenado, 1)    : null,
            'ef_total'        => $ef_total      ? round($ef_total, 1)      : null,
            'rend_botellas'   => $rend_botellas,
            'abv_real'        => $abv_real,
            'og_real'         => $og ?: null,
            'fg_real'         => $fg ?: null,
        ];
    }
}
