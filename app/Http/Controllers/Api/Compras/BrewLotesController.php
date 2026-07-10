<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\BrewLote;
use App\Models\BrewReceta;
use App\Models\Empleado;
use App\Models\Departamento;
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
            'planta'         => 'nullable|string|max:20',
            'num_cocciones'  => 'nullable|integer|min:1|max:10',
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
            'og_real'               => 'nullable|numeric',
            'vol_preboil_real'      => 'nullable|numeric|min:0',
            'vol_postboil_real'     => 'nullable|numeric|min:0',
            'temp_mash_real'        => 'nullable|numeric',
            'tiempo_boil_min'       => 'nullable|integer|min:0',
            'notas'                 => 'nullable|string',
            // proceso completo
            'ph_mash_real'          => 'nullable|numeric|min:0|max:14',
            'ph_postboil'           => 'nullable|numeric|min:0|max:14',
            'molino_kg_real'        => 'nullable|numeric|min:0',
            'molino_tiempo_min'     => 'nullable|integer|min:0',
            'whirlpool_hora'        => 'nullable|string|max:8',
            'whirlpool_temp'        => 'nullable|numeric',
            'enf_temp_entrada'      => 'nullable|numeric',
            'enf_temp_refrigerante' => 'nullable|numeric',
            'enf_temp_salida'       => 'nullable|numeric',
            'enf_tiempo_min'        => 'nullable|integer|min:0',
            'oxig_temp'             => 'nullable|numeric',
            'oxig_nivel'            => 'nullable|string|max:30',
            'macerado_pasos'        => 'array',
            'boil_pasos'            => 'array',
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
                        'ph_real'     => $row['ph_real'] ?? null,
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
                $lote->update(['estado' => 'fermentacion']);
            }
        });

        return response()->json(['ok' => true, 'estado' => $lote->fresh()->estado]);
    }

    public function guardarFiltracion(Request $request, $id)
    {
        $lote = BrewLote::findOrFail($id);
        $data = $request->validate([
            'vol_bbt_real'                => 'nullable|numeric|min:0',
            'og_bbt'                      => 'nullable|numeric',
            'temp_transfer'               => 'nullable|numeric',
            'num_corridas'                => 'nullable|integer|min:1',
            'notas'                       => 'nullable|string',
            'corridas'                    => 'array',
            'corridas.*.vol_litros'       => 'nullable|numeric|min:0',
            'corridas.*.vol_inicial'      => 'nullable|numeric|min:0',
            'corridas.*.vol_final'        => 'nullable|numeric|min:0',
            'corridas.*.tierra_blanca'    => 'nullable|numeric|min:0',
            'corridas.*.tierra_roja'      => 'nullable|numeric|min:0',
            'corridas.*.densidad'         => 'nullable|numeric',
            'corridas.*.hora'             => 'nullable|string|max:8',
            'corridas.*.timestamp_inicio' => 'nullable|string|max:20',
            'corridas.*.notas'            => 'nullable|string',
        ]);

        DB::connection('compras')->transaction(function () use ($lote, $data) {
            $lote->filtracion()->updateOrCreate(
                ['brew_lote_id' => $lote->id],
                collect($data)->except('corridas')->toArray()
            );

            if (isset($data['corridas'])) {
                $lote->filtracionCorridas()->delete();
                foreach ($data['corridas'] as $i => $row) {
                    $lote->filtracionCorridas()->create(array_merge(
                        collect($row)->only([
                            'vol_litros', 'vol_inicial', 'vol_final',
                            'tierra_blanca', 'tierra_roja',
                            'densidad', 'hora', 'timestamp_inicio', 'notas',
                        ])->toArray(),
                        ['numero_corrida' => $i + 1]
                    ));
                }
            }

            if ($lote->estado === 'filtracion') {
                $lote->update(['estado' => 'llenado']);
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
            'drest_inicio_dia'    => 'nullable|integer|min:1',
            'drest_temp'          => 'nullable|numeric',
            'drest_resultado'     => 'nullable|string|max:500',
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
                    collect($dia)->only(['dia', 'fecha', 'gravedad', 'temp', 'ph', 'notas'])->toArray()
                );
            }
            // Nuevo orden: seguimiento → filtracion
            if ($lote->estado === 'seguimiento') {
                $lote->update(['estado' => 'filtracion']);
            }
        });

        return response()->json(['ok' => true, 'estado' => $lote->fresh()->estado]);
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

    // ─── Siguiente código de lote automático ─────────────────────────────────

    public function siguienteCodigo(Request $request)
    {
        $recetaId = $request->query('receta_id');
        $planta   = $request->query('planta', 'sivar');

        $plantaCod = $planta === 'zr' ? 'ZR' : 'SV';
        $yymm      = now()->setTimezone('America/El_Salvador')->format('ym');

        $prefijo = null;
        if ($recetaId) {
            $receta = BrewReceta::find($recetaId);
            if ($receta) {
                // Generar abreviatura de 4 letras del nombre de cerveza
                $palabras = preg_split('/\s+/', strtoupper(preg_replace('/[^a-zA-Z\s]/', '', $receta->nombre)));
                if (count($palabras) === 1) {
                    $prefijo = substr($palabras[0], 0, 4);
                } else {
                    $prefijo = collect($palabras)->map(fn($p) => substr($p, 0, 2))->implode('');
                    $prefijo = substr($prefijo, 0, 4);
                }
            }
        }
        $prefijo = $prefijo ?? 'BREW';

        // Buscar el último número correlativo del mes actual para esta cerveza+planta
        $patron = "CAD-{$prefijo}-{$yymm}-";
        $ultimo = DB::connection('compras')
            ->table('brew_lotes')
            ->where('codigo_lote', 'like', $patron . '%')
            ->orderBy('codigo_lote', 'desc')
            ->value('codigo_lote');

        $seq = 1;
        if ($ultimo) {
            $partes = explode('-', $ultimo);
            $seq = (int) end($partes) + 1;
        }

        return response()->json([
            'codigo' => sprintf('CAD-%s-%s-%02d', $prefijo, $yymm, $seq),
        ]);
    }

    // ─── Empleados activos de una planta ─────────────────────────────────────

    public function empleadosPlanta(Request $request)
    {
        $planta = $request->query('planta', 'sivar');
        // Los codigos de departamento: PROD_SV = Planta Sivar, PROD_ZR = Planta ZR
        $codigoDpto = $planta === 'zr' ? 'PROD_ZR' : 'PROD_SV';

        try {
            $dpto = DB::connection('pgsql')
                ->table('departamentos')
                ->where('codigo', $codigoDpto)
                ->where('activo', true)
                ->first();

            if (!$dpto) {
                return response()->json([]);
            }

            $ids = DB::connection('pgsql')
                ->table('empleados')
                ->where('departamento_id', $dpto->id)
                ->where('activo', true)
                ->pluck('id')
                ->toArray();

            // Incluir también al jefe del departamento aunque no esté asignado al depto
            if ($dpto->jefe_empleado_id && !in_array($dpto->jefe_empleado_id, $ids)) {
                $ids[] = $dpto->jefe_empleado_id;
            }

            $empleados = DB::connection('pgsql')
                ->table('empleados')
                ->whereIn('id', $ids)
                ->where('activo', true)
                ->select('id', 'nombres', 'apellidos')
                ->orderBy('nombres')
                ->get()
                ->map(fn($e) => [
                    'id'     => $e->id,
                    'nombre' => trim($e->nombres . ' ' . $e->apellidos),
                ]);

            return response()->json($empleados);
        } catch (\Exception $e) {
            return response()->json([]);
        }
    }

    // ─── Estadísticas de producción ──────────────────────────────────────────

    public function estadisticas(Request $request)
    {
        $query = BrewLote::with(['receta:id,nombre,estilo', 'llenadoBotellas', 'llenadoBarriles'])
            ->orderBy('fecha_coccion', 'desc');

        // ── Filtros ────────────────────────────────────────────────────────
        if ($request->filled('desde')) {
            $query->whereDate('fecha_coccion', '>=', $request->desde);
        }
        if ($request->filled('hasta')) {
            $query->whereDate('fecha_coccion', '<=', $request->hasta);
        }
        if ($request->filled('estado')) {
            $query->where('estado', $request->estado);
        }
        if ($request->filled('planta')) {
            $query->where('planta', $request->planta);
        }

        $lotes = $query->get();

        // Por cerveza
        $porCerveza = $lotes->groupBy('receta.nombre')->map(function ($grupo, $nombre) {
            $totalLotes  = $grupo->count();
            $completados = $grupo->where('estado', 'completo')->count();
            $volTotal = $grupo->sum(function ($l) {
                $vb = ($l->llenadoBotellas->botellas_buenas ?? 0) * 0.330;
                $vr = (($l->llenadoBarriles->barriles_6th ?? 0) * 19.8)
                     + (($l->llenadoBarriles->barriles_half ?? 0) * 58.7);
                return $vb + $vr;
            });
            return ['nombre' => $nombre ?? '—', 'totalLotes' => $totalLotes, 'completados' => $completados, 'volTotal' => round($volTotal, 2)];
        })->sortByDesc('totalLotes')->values();

        // Por mes
        $porMes = $lotes->groupBy(fn($l) => substr($l->fecha_coccion, 0, 7))
            ->map(fn($g, $mes) => ['mes' => $mes, 'lotes' => $g->count()])
            ->sortBy('mes')
            ->values();

        // Estados actuales
        $estados = $lotes->groupBy('estado')
            ->map(fn($g, $k) => ['estado' => $k, 'count' => $g->count()])
            ->values();

        // Por planta
        $porPlanta = $lotes->groupBy(fn($l) => $l->planta ?? 'sivar')
            ->map(fn($g, $k) => ['planta' => $k, 'count' => $g->count()])
            ->values();

        return response()->json([
            'total_lotes'      => $lotes->count(),
            'en_proceso'       => $lotes->where('estado', '!=', 'completo')->count(),
            'completados'      => $lotes->where('estado', 'completo')->count(),
            'por_cerveza'      => $porCerveza,
            'por_mes'          => $porMes,
            'estados'          => $estados,
            'por_planta'       => $porPlanta,
        ]);
    }
}
