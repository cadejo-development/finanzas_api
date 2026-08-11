<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\BrewLote;
use App\Models\BrewLoteIngrediente;
use App\Models\BrewReceta;
use App\Models\BrewLevaduraLote;
use App\Models\BrewLevaduraPitch;
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
            'receta.levaduras', 'receta.maceradoPasos', 'receta.boilPasos', 'receta.diasObjetivo',
            'cocciones', 'maceradoPasos', 'boilPasos',
            'filtracion', 'filtracionCorridas',
            'fermentacion', 'fermSeguimiento',
            'llenadoBotellas', 'llenadoBarriles',
            'llenadoBotellasCorridas', 'llenadoBarrilesCorridas',
            'levaduraPitches.levaduraLote',
            'ingredientes',
        ])->findOrFail($id);

        $this->syncPasosDesdeReceta($lote);
        $this->syncIngredientesDesdeReceta($lote);
        $lote->load('ingredientes');

        return array_merge($lote->toArray(), ['reporte' => $this->calcularReporte($lote)]);
    }

    /**
     * Mantiene los pasos del lote sincronizados con la receta.
     * Crea pasos que falten, actualiza config (nombre/temp/tiempo) sin tocar mediciones,
     * y elimina pasos que ya no existan en la receta.
     */
    private function syncPasosDesdeReceta(BrewLote $lote): void
    {
        $numCocciones = (int) ($lote->num_cocciones ?? 1);
        $recetaPasos  = $lote->receta->maceradoPasos->keyBy('orden');
        $recetaBoil   = $lote->receta->boilPasos->keyBy('orden');

        for ($n = 1; $n <= $numCocciones; $n++) {
            // ── Macerado ────────────────────────────────────────────────────
            $lotePasos = $lote->maceradoPasos->where('coccion_numero', $n)->keyBy('orden');

            foreach ($recetaPasos as $orden => $rp) {
                if ($lotePasos->has($orden)) {
                    $lotePasos[$orden]->update([
                        'nombre'         => $rp->nombre,
                        'temp_objetivo'  => $rp->temp_objetivo,
                        'tiempo_min'     => $rp->tiempo_min,
                        'ph_objetivo'    => $rp->ph_objetivo,
                        'adicion_agua_l' => $rp->adicion_agua_l,
                    ]);
                } else {
                    $lote->maceradoPasos()->create([
                        'coccion_numero' => $n,
                        'orden'          => $orden,
                        'nombre'         => $rp->nombre,
                        'temp_objetivo'  => $rp->temp_objetivo,
                        'tiempo_min'     => $rp->tiempo_min,
                        'ph_objetivo'    => $rp->ph_objetivo,
                        'adicion_agua_l' => $rp->adicion_agua_l,
                    ]);
                }
            }
            foreach ($lotePasos as $orden => $lp) {
                if (!$recetaPasos->has($orden)) $lp->delete();
            }

            // ── Boil pasos ──────────────────────────────────────────────────
            $loteBoil = $lote->boilPasos->where('coccion_numero', $n)->keyBy('orden');

            foreach ($recetaBoil as $orden => $rb) {
                $boilObj = [
                    'descripcion'       => $rb->descripcion,
                    'tiempo_min'        => $rb->tiempo_min,
                    'fase'              => $rb->fase ?? 'hervor',
                    'cantidad_objetivo' => $rb->cantidad_objetivo,
                    'unidad'            => $rb->unidad,
                    'plato_objetivo'    => $rb->plato_objetivo,
                    'vol_objetivo_l'    => $rb->vol_objetivo_l,
                ];
                if ($loteBoil->has($orden)) {
                    $loteBoil[$orden]->update($boilObj);
                } else {
                    $lote->boilPasos()->create(array_merge($boilObj, [
                        'coccion_numero' => $n,
                        'orden'          => $orden,
                        'completado'     => false,
                    ]));
                }
            }
            foreach ($loteBoil as $orden => $lb) {
                if (!$recetaBoil->has($orden)) $lb->delete();
            }
        }

        $lote->load('maceradoPasos', 'boilPasos');
    }

    /**
     * Sincroniza brew_lote_ingredientes con la receta actual.
     * Solo crea registros que falten — nunca elimina ni sobreescribe estado/notas.
     */
    private function syncIngredientesDesdeReceta(BrewLote $lote): void
    {
        $existentes = $lote->ingredientes->map(fn($i) => $i->tipo . ':' . $i->nombre)->flip();

        $insertar = [];
        $now = now();

        foreach ($lote->receta->maltas as $m) {
            if (empty($m->nombre)) continue;
            $key = 'malta:' . $m->nombre;
            if (!$existentes->has($key)) {
                $insertar[] = [
                    'brew_lote_id'      => $lote->id,
                    'tipo'              => 'malta',
                    'nombre'            => $m->nombre,
                    'cantidad_objetivo' => $m->cantidad_lb,
                    'unidad'            => 'lb',
                    'detalle'           => $m->proveedor,
                    'estado'            => 'pendiente',
                    'notas'             => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }
        }

        foreach ($lote->receta->lupulos as $l) {
            if (empty($l->nombre)) continue;
            $key = 'lupulo:' . $l->nombre;
            if (!$existentes->has($key)) {
                $insertar[] = [
                    'brew_lote_id'      => $lote->id,
                    'tipo'              => 'lupulo',
                    'nombre'            => $l->nombre,
                    'cantidad_objetivo' => $l->cantidad_g,
                    'unidad'            => 'g',
                    'detalle'           => trim(($l->uso ?? '') . ($l->tiempo_min ? ' ' . $l->tiempo_min . ' min' : '')),
                    'estado'            => 'pendiente',
                    'notas'             => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }
        }

        foreach ($lote->receta->minerales as $mn) {
            if (empty($mn->nombre)) continue;
            $key = 'mineral:' . $mn->nombre;
            if (!$existentes->has($key)) {
                $insertar[] = [
                    'brew_lote_id'      => $lote->id,
                    'tipo'              => 'mineral',
                    'nombre'            => $mn->nombre,
                    'cantidad_objetivo' => $mn->cantidad_g,
                    'unidad'            => 'g',
                    'detalle'           => $mn->fase,
                    'estado'            => 'pendiente',
                    'notas'             => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }
        }

        foreach ($lote->receta->levaduras as $lv) {
            if (empty($lv->nombre)) continue;
            $key = 'levadura:' . $lv->nombre;
            if (!$existentes->has($key)) {
                $insertar[] = [
                    'brew_lote_id'      => $lote->id,
                    'tipo'              => 'levadura',
                    'nombre'            => $lv->nombre,
                    'cantidad_objetivo' => $lv->cantidad_g,
                    'unidad'            => 'g',
                    'detalle'           => $lv->proveedor,
                    'estado'            => 'pendiente',
                    'notas'             => null,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ];
            }
        }

        if (!empty($insertar)) {
            DB::connection('compras')->table('brew_lote_ingredientes')->insert($insertar);
        }
    }

    public function updateIngrediente(Request $request, $loteId, $ingId)
    {
        $ing = BrewLoteIngrediente::where('brew_lote_id', $loteId)->findOrFail($ingId);
        $data = $request->validate([
            'estado'         => 'required|in:pendiente,en_proceso,parcial,agregado',
            'notas'          => 'nullable|string|max:500',
            'cantidad_real'  => 'nullable|numeric|min:0',
        ]);
        $ing->update($data);
        return response()->json($ing);
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
            $receta       = BrewReceta::with(['maceradoPasos', 'boilPasos'])->find($data['brew_receta_id']);
            $numCocciones = (int) ($data['num_cocciones'] ?? 1);
            // Crear un juego de pasos macerado+boil por cada cocción
            for ($n = 1; $n <= $numCocciones; $n++) {
                foreach ($receta->maceradoPasos as $paso) {
                    $lote->maceradoPasos()->create([
                        'coccion_numero' => $n,
                        'orden'          => $paso->orden,
                        'nombre'         => $paso->nombre,
                        'temp_objetivo'  => $paso->temp_objetivo,
                        'tiempo_min'     => $paso->tiempo_min,
                        'ph_objetivo'    => $paso->ph_objetivo,
                        'adicion_agua_l' => $paso->adicion_agua_l,
                    ]);
                }
                foreach ($receta->boilPasos as $paso) {
                    $lote->boilPasos()->create([
                        'coccion_numero'    => $n,
                        'orden'             => $paso->orden,
                        'descripcion'       => $paso->descripcion,
                        'tiempo_min'        => $paso->tiempo_min,
                        'fase'              => $paso->fase ?? 'hervor',
                        'completado'        => false,
                        'cantidad_objetivo' => $paso->cantidad_objetivo,
                        'unidad'            => $paso->unidad,
                        'plato_objetivo'    => $paso->plato_objetivo,
                        'vol_objetivo_l'    => $paso->vol_objetivo_l,
                    ]);
                }
            }
        });

        return $lote->load(['receta:id,nombre,estilo,codigo']);
    }

    // ─── Etapas del wizard ───────────────────────────────────────────────────

    public function guardarCoccion(Request $request, $id)
    {
        $lote = BrewLote::findOrFail($id);
        $data = $request->validate([
            'coccion_numero'        => 'nullable|integer|min:1|max:10',
            'vol_preboil_obj'       => 'nullable|numeric|min:0',
            'vol_postboil_obj'      => 'nullable|numeric|min:0',
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

        $coccionNumero = (int) ($data['coccion_numero'] ?? 1);

        DB::connection('compras')->transaction(function () use ($lote, $data, $coccionNumero) {
            $lote->cocciones()->updateOrCreate(
                ['brew_lote_id' => $lote->id, 'numero' => $coccionNumero],
                collect($data)->except(['coccion_numero', 'macerado_pasos', 'boil_pasos'])->toArray()
            );

            if (isset($data['macerado_pasos'])) {
                foreach ($data['macerado_pasos'] as $row) {
                    $lote->maceradoPasos()->where('id', $row['id'])->update([
                        'temp_real'       => $row['temp_real']       ?? null,
                        'hora_inicio'     => $row['hora_inicio']     ?? null,
                        'hora_fin'        => $row['hora_fin']        ?? null,
                        'ph_real'         => $row['ph_real']         ?? null,
                        'vol_agua_real_l' => $row['vol_agua_real_l'] ?? null,
                        'adiciones_reales'=> $row['adiciones_reales'] ?? null,
                        'notas'           => $row['notas']           ?? null,
                    ]);
                }
            }
            if (isset($data['boil_pasos'])) {
                foreach ($data['boil_pasos'] as $row) {
                    $lote->boilPasos()->where('id', $row['id'])->update([
                        'hora'              => $row['hora']              ?? null,
                        'completado'        => $row['completado']        ?? false,
                        'timestamp_adicion' => $row['timestamp_adicion'] ?? null,
                        'ingrediente_real'  => $row['ingrediente_real']  ?? null,
                        't_transcu_real'    => $row['t_transcu_real']    ?? null,
                        'cantidad_real'     => $row['cantidad_real']     ?? null,
                        'plato_real'        => $row['plato_real']        ?? null,
                        'vol_real_l'        => $row['vol_real_l']        ?? null,
                        'notas'             => $row['notas']             ?? null,
                    ]);
                }
            }

            // Avanzar estado solo cuando se guarda la última cocción
            if ($lote->estado === 'coccion' && $coccionNumero >= ($lote->num_cocciones ?? 1)) {
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
            'fecha_pitch'           => 'required|date',
            'temp_pitch'            => 'nullable|numeric',
            'og_pitch'              => 'nullable|numeric',
            'vol_pitch'             => 'nullable|numeric',
            'levadura_nombre'       => 'nullable|string|max:100',
            'levadura_cantidad_g'   => 'nullable|numeric',
            'levadura_vol_pitch_ml' => 'nullable|numeric|min:0',
            'levadura_lote_id'      => 'nullable|exists:compras.brew_levadura_lotes,id',
            'notas'                 => 'nullable|string',
            'drest_inicio_dia'      => 'nullable|integer|min:1',
            'drest_temp'            => 'nullable|numeric',
            'drest_resultado'       => 'nullable|string|max:500',
        ]);

        $lote->fermentacion()->updateOrCreate(
            ['brew_lote_id' => $lote->id],
            collect($data)->except(['levadura_lote_id', 'levadura_vol_pitch_ml'])->toArray()
        );

        // Registrar pitch en inventario de levadura si se seleccionó un lote
        $levLoteId = $data['levadura_lote_id'] ?? null;
        if ($levLoteId) {
            $levLote = BrewLevaduraLote::find($levLoteId);
            if ($levLote) {
                $volPitchMl = $data['levadura_vol_pitch_ml'] ?? $data['levadura_cantidad_g'] ?? null;
                $costoEstimado = null;
                if ($levLote->precio_total_usd && $levLote->vol_total_ml && $volPitchMl) {
                    $costoEstimado = round(
                        ((float) $volPitchMl / (float) $levLote->vol_total_ml) * (float) $levLote->precio_total_usd,
                        4
                    );
                }
                // Actualizar o crear el pitch de este lote cervecero
                BrewLevaduraPitch::updateOrCreate(
                    ['brew_levadura_lote_id' => $levLote->id, 'brew_lote_id' => $lote->id],
                    [
                        'fecha'               => $data['fecha_pitch'],
                        'vol_pitch_ml'        => $volPitchMl,
                        'og_pitch'            => $data['og_pitch'] ?? null,
                        'temp_pitch'          => $data['temp_pitch'] ?? null,
                        'costo_estimado_usd'  => $costoEstimado,
                    ]
                );
            }
        }

        if ($lote->estado === 'fermentacion') {
            $lote->update(['estado' => 'seguimiento']);
        }

        return response()->json(['ok' => true]);
    }

    public function guardarSeguimiento(Request $request, $id)
    {
        $lote = BrewLote::findOrFail($id);
        $data = $request->validate([
            'dias'                    => 'required|array',
            'dias.*.dia'              => 'required|integer|min:1',
            'dias.*.fecha'            => 'required|date',
            'dias.*.gravedad'         => 'nullable|numeric',
            'dias.*.temp'             => 'nullable|numeric',
            'dias.*.ph'               => 'nullable|numeric',
            'dias.*.gravedad_obj'     => 'nullable|numeric',
            'dias.*.temp_obj'         => 'nullable|numeric',
            'dias.*.ph_obj'           => 'nullable|numeric',
            'dias.*.accion_ajuste'    => 'nullable|string|max:1000',
            'dias.*.notas'            => 'nullable|string',
            'cerrar_seguimiento'      => 'boolean',
        ]);

        $cerrar = $request->boolean('cerrar_seguimiento', false);

        DB::connection('compras')->transaction(function () use ($lote, $data, $cerrar) {
            foreach ($data['dias'] as $dia) {
                $lote->fermSeguimiento()->updateOrCreate(
                    ['brew_lote_id' => $lote->id, 'dia' => $dia['dia']],
                    collect($dia)->only([
                        'dia', 'fecha', 'gravedad', 'temp', 'ph',
                        'gravedad_obj', 'temp_obj', 'ph_obj', 'accion_ajuste', 'notas',
                    ])->toArray()
                );
            }
            if ($lote->estado === 'seguimiento' && $cerrar) {
                $lote->update(['estado' => 'filtracion']);
            }
        });

        return response()->json(['ok' => true, 'estado' => $lote->fresh()->estado]);
    }

    public function guardarLlenado(Request $request, $id)
    {
        $lote = BrewLote::findOrFail($id);
        $data = $request->validate([
            // ── Nuevo: corridas multi-run ──────────────────────────────────
            'botellas_corridas'                        => 'nullable|array',
            'botellas_corridas.*.fecha'                => 'nullable|date',
            'botellas_corridas.*.bbt_vol_inicio'       => 'nullable|numeric|min:0',
            'botellas_corridas.*.bbt_vol_fin'          => 'nullable|numeric|min:0',
            'botellas_corridas.*.botellas_peracético'  => 'nullable|integer|min:0',
            'botellas_corridas.*.botellas_buenas'      => 'nullable|integer|min:0',
            'botellas_corridas.*.bajo_nivel'           => 'nullable|integer|min:0',
            'botellas_corridas.*.derrame'              => 'nullable|integer|min:0',
            'botellas_corridas.*.vol_llenado_l'        => 'nullable|numeric|min:0',
            'botellas_corridas.*.eficiencia'           => 'nullable|numeric',
            'botellas_corridas.*.cajas'                => 'nullable|integer|min:0',
            'botellas_corridas.*.fg_real'              => 'nullable|numeric',
            'botellas_corridas.*.co2_vol'              => 'nullable|numeric',
            'botellas_corridas.*.notas'                => 'nullable|string',
            'barriles_corridas'                        => 'nullable|array',
            'barriles_corridas.*.fecha'                => 'nullable|date',
            'barriles_corridas.*.bbt_vol_inicio'       => 'nullable|numeric|min:0',
            'barriles_corridas.*.bbt_vol_fin'          => 'nullable|numeric|min:0',
            'barriles_corridas.*.barriles_6th'         => 'nullable|integer|min:0',
            'barriles_corridas.*.barriles_half'        => 'nullable|integer|min:0',
            'barriles_corridas.*.derrame'              => 'nullable|numeric|min:0',
            'barriles_corridas.*.eficiencia'           => 'nullable|numeric',
            'barriles_corridas.*.fg_real'              => 'nullable|numeric',
            'barriles_corridas.*.co2_psi'              => 'nullable|numeric',
            'barriles_corridas.*.notas'                => 'nullable|string',
            // ── Legado: registro único (backward compat) ───────────────────
            'botellas'                                 => 'nullable|array',
            'barriles'                                 => 'nullable|array',
        ]);

        DB::connection('compras')->transaction(function () use ($lote, $data) {

            // ── Botellas corridas ──────────────────────────────────────────
            if (isset($data['botellas_corridas'])) {
                $lote->llenadoBotellasCorridas()->delete();
                $totalBuenas = 0;
                $firstFg = null; $firstCo2 = null;
                $firstFecha = null; $firstVolInicio = null; $lastVolFin = null;

                foreach ($data['botellas_corridas'] as $i => $corrida) {
                    $lote->llenadoBotellasCorridas()->create(array_merge(
                        collect($corrida)->only([
                            'fecha', 'bbt_vol_inicio', 'bbt_vol_fin',
                            'botellas_peracético',
                            'botellas_buenas', 'bajo_nivel', 'derrame',
                            'vol_llenado_l', 'eficiencia', 'cajas',
                            'fg_real', 'co2_vol', 'notas',
                        ])->toArray(),
                        ['numero_corrida' => $i + 1]
                    ));
                    $totalBuenas += (int) ($corrida['botellas_buenas'] ?? 0);
                    if ($i === 0) {
                        $firstFg        = $corrida['fg_real'] ?? null;
                        $firstCo2       = $corrida['co2_vol'] ?? null;
                        $firstFecha     = $corrida['fecha'] ?? null;
                        $firstVolInicio = $corrida['bbt_vol_inicio'] ?? null;
                    }
                    $lastVolFin = $corrida['bbt_vol_fin'] ?? null;
                }

                // Actualizar tabla legacy para compatibilidad con reporte
                $lote->llenadoBotellas()->updateOrCreate(
                    ['brew_lote_id' => $lote->id],
                    [
                        'fecha'          => $firstFecha ?? now()->toDateString(),
                        'botellas_buenas'=> $totalBuenas,
                        'vol_inicio'     => $firstVolInicio,
                        'vol_fin'        => $lastVolFin,
                        'fg_real'        => $firstFg,
                        'co2_vol'        => $firstCo2,
                    ]
                );
            } elseif (!empty($data['botellas'])) {
                $lote->llenadoBotellas()->updateOrCreate(
                    ['brew_lote_id' => $lote->id],
                    $data['botellas']
                );
            }

            // ── Barriles corridas ──────────────────────────────────────────
            if (isset($data['barriles_corridas'])) {
                $lote->llenadoBarrilesCorridas()->delete();
                $totalSixth = 0; $totalHalf = 0; $firstFecha = null;
                $firstFg = null; $firstPsi = null;

                foreach ($data['barriles_corridas'] as $i => $corrida) {
                    $lote->llenadoBarrilesCorridas()->create(array_merge(
                        collect($corrida)->only([
                            'fecha', 'bbt_vol_inicio', 'bbt_vol_fin',
                            'barriles_6th', 'barriles_half', 'derrame',
                            'eficiencia', 'fg_real', 'co2_psi', 'notas',
                        ])->toArray(),
                        ['numero_corrida' => $i + 1]
                    ));
                    $totalSixth += (int) ($corrida['barriles_6th'] ?? 0);
                    $totalHalf  += (int) ($corrida['barriles_half'] ?? 0);
                    if ($i === 0) {
                        $firstFecha = $corrida['fecha'] ?? null;
                        $firstFg    = $corrida['fg_real'] ?? null;
                        $firstPsi   = $corrida['co2_psi'] ?? null;
                    }
                }

                $volTotal = $totalSixth * 19.8 + $totalHalf * 58.7;
                $lote->llenadoBarriles()->updateOrCreate(
                    ['brew_lote_id' => $lote->id],
                    [
                        'fecha'               => $firstFecha ?? now()->toDateString(),
                        'barriles_6th'        => $totalSixth,
                        'barriles_half'       => $totalHalf,
                        'vol_total_barriles'  => $volTotal,
                        'fg_real'             => $firstFg,
                        'co2_psi'             => $firstPsi,
                    ]
                );
            } elseif (!empty($data['barriles'])) {
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
            'receta', 'cocciones', 'filtracion', 'fermentacion',
            'fermSeguimiento', 'llenadoBotellas', 'llenadoBarriles',
        ])->findOrFail($id);

        return $this->calcularReporte($lote);
    }

    // ─── Cálculo de eficiencias ───────────────────────────────────────────────

    private function calcularReporte(BrewLote $lote): array
    {
        $receta    = $lote->receta;
        $cocciones = $lote->relationLoaded('cocciones')
            ? $lote->cocciones
            : $lote->cocciones()->get();
        $filtr    = $lote->filtracion;
        $botellas = $lote->llenadoBotellas;
        $barriles = $lote->llenadoBarriles;

        // Volúmenes reales — suma de todas las cocciones
        $volPreboil  = $cocciones->sum(fn($c) => (float) ($c->vol_preboil_real  ?? 0));
        $volPostboil = $cocciones->sum(fn($c) => (float) ($c->vol_postboil_real ?? 0));
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
        $coccion1 = $cocciones->first();
        $og   = (float) ($lote->fermentacion->og_pitch ?? $coccion1?->og_real ?? 0);
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
