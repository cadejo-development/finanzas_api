<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Comparativa Brilo ↔ Sistema propio.
 *
 * GET /api/compras/comparador-brilo                  → lista comparativa
 * GET /api/compras/comparador-brilo/{codigo}/detalle → ingredientes lado a lado
 * GET /api/compras/comparador-brilo/{codigo}/historial → auditoría de cambios
 */
class ComparadorBriloController extends Controller
{
    public function index(Request $request)
    {
        $this->requireAdminRecetas();

        $filtro    = $request->query('filtro', 'todos');
        $buscar    = trim($request->query('buscar', ''));
        $tipo      = $request->query('tipo');
        $categoria = $request->query('categoria');
        $estado    = $request->query('estado');
        $perPage   = min((int) $request->query('per_page', 50), 200);
        $page      = max(1, (int) $request->query('page', 1));

        // FULL OUTER JOIN en SQL para no perder registros con colecciones PHP
        $sql = "
            SELECT
                COALESCE(b.codigo, r.codigo_origen)       AS codigo,
                b.nombre                                   AS brilo_nombre,
                r.nombre                                   AS sistema_nombre,
                COALESCE(b.tipo_receta, r.tipo_receta)    AS tipo_receta,
                b.categoria_nombre                         AS brilo_categoria,
                rc.nombre                                  AS sistema_categoria,
                b.precio                                   AS brilo_precio,
                b.activo                                   AS brilo_activo,
                r.activa                                   AS sistema_activa,
                er.codigo                                  AS sistema_estado,
                b.no_enviar_cocina,
                b.en_boton_cocina,
                b.sucursales                               AS brilo_sucursales,
                b.synced_at,
                r.modificado_localmente,
                r.sincronizado_brilo,
                COALESCE(bi_c.cnt, 0)                     AS brilo_num_ing,
                COALESCE(ri_c.cnt, 0)                     AS sistema_num_ing
            FROM brilo_snapshot_recetas b
            FULL OUTER JOIN recetas r
                ON r.codigo_origen = b.codigo
            LEFT JOIN receta_categorias rc
                ON rc.id = r.categoria_id
            LEFT JOIN estados_receta er
                ON er.id = r.estado_id
            LEFT JOIN (
                SELECT receta_codigo, COUNT(*) AS cnt
                FROM   brilo_snapshot_ingredientes
                WHERE  activo = true
                GROUP  BY receta_codigo
            ) bi_c ON bi_c.receta_codigo = b.codigo
            LEFT JOIN (
                SELECT receta_id, COUNT(*) AS cnt
                FROM   receta_ingredientes
                GROUP  BY receta_id
            ) ri_c ON ri_c.receta_id = r.id
            WHERE (b.codigo IS NOT NULL)
               OR (r.codigo_origen IS NOT NULL AND r.codigo_origen <> '')
            ORDER BY COALESCE(b.codigo, r.codigo_origen)
        ";

        $raw = DB::connection('compras')->select($sql);

        $rows = collect($raw)->map(function ($r) {
            $soloEnBrilo   = $r->brilo_nombre !== null && $r->sistema_nombre === null;
            $soloEnSistema = $r->brilo_nombre === null && $r->sistema_nombre !== null;
            $enAmbos       = $r->brilo_nombre !== null && $r->sistema_nombre !== null;

            $nombreDif = $enAmbos && strtolower(trim($r->brilo_nombre)) !== strtolower(trim($r->sistema_nombre));
            $activoDif = $enAmbos && $this->boolVal($r->brilo_activo) !== $this->boolVal($r->sistema_activa);
            $ingDif    = $enAmbos && (int) $r->brilo_num_ing !== (int) $r->sistema_num_ing;
            $hayDif    = $soloEnBrilo || $soloEnSistema || $nombreDif || $activoDif || $ingDif;

            return [
                'codigo'             => $r->codigo,
                'brilo_nombre'       => $r->brilo_nombre,
                'sistema_nombre'     => $r->sistema_nombre,
                'tipo_receta'        => $r->tipo_receta,
                'brilo_categoria'    => $r->brilo_categoria,
                'sistema_categoria'  => $r->sistema_categoria,
                'brilo_precio'       => $r->brilo_precio,
                'brilo_activo'       => $r->brilo_activo !== null ? $this->boolVal($r->brilo_activo) : null,
                'sistema_activa'     => $r->sistema_activa !== null ? $this->boolVal($r->sistema_activa) : null,
                'sistema_estado'     => $r->sistema_estado ?? null,
                'no_enviar_cocina'   => $r->no_enviar_cocina !== null ? $this->boolVal($r->no_enviar_cocina) : null,
                'en_boton_cocina'    => $r->en_boton_cocina !== null ? $this->boolVal($r->en_boton_cocina) : null,
                'brilo_sucursales'   => $r->brilo_sucursales ? json_decode($r->brilo_sucursales) : [],
                'brilo_num_ing'      => (int) $r->brilo_num_ing,
                'sistema_num_ing'    => (int) $r->sistema_num_ing,
                'modificado_local'   => $r->modificado_localmente !== null ? $this->boolVal($r->modificado_localmente) : null,
                'sincronizado_brilo' => $r->sincronizado_brilo !== null ? $this->boolVal($r->sincronizado_brilo) : null,
                'solo_en_brilo'      => $soloEnBrilo,
                'solo_en_sistema'    => $soloEnSistema,
                'hay_diferencia'     => $hayDif,
                'diferencias'        => array_values(array_filter([
                    $soloEnBrilo ? 'solo_en_brilo'  : null,
                    $soloEnSistema ? 'solo_en_sistema' : null,
                    $nombreDif   ? 'nombre'         : null,
                    $activoDif   ? 'estado'         : null,
                    $ingDif      ? 'ingredientes'   : null,
                ])),
                'synced_at' => $r->synced_at,
            ];
        });

        // ── Comparación profunda de ingredientes para detectar diferencias reales ──
        // La comparación inicial solo verifica conteo (brilo_num_ing vs sistema_num_ing).
        // Aquí cargamos los ingredientes reales de las recetas que aparecen como "igual"
        // y verificamos si las cantidades coinciden (con conversión de unidades).
        $codigosCandidatos = $rows
            ->filter(fn ($r) => !$r['hay_diferencia'] && $r['brilo_nombre'] !== null && $r['sistema_nombre'] !== null)
            ->pluck('codigo')
            ->values()
            ->all();

        if (!empty($codigosCandidatos)) {
            $briloIngMapDeep = DB::connection('compras')
                ->table('brilo_snapshot_ingredientes')
                ->whereIn('receta_codigo', $codigosCandidatos)
                ->where('activo', true)
                ->get(['receta_codigo', 'ingrediente_codigo', 'cantidad_base', 'unidad_base', 'cantidad_pres', 'unidad_pres'])
                ->groupBy('receta_codigo');

            $sistemaIngMapDeep = DB::connection('compras')
                ->table('receta_ingredientes as ri')
                ->join('recetas as rec', 'ri.receta_id', '=', 'rec.id')
                ->leftJoin('productos as p',  'ri.producto_id',   '=', 'p.id')
                ->leftJoin('recetas as sr',   'ri.sub_receta_id', '=', 'sr.id')
                ->whereIn('rec.codigo_origen', $codigosCandidatos)
                ->select([
                    'rec.codigo_origen as receta_codigo',
                    DB::raw("COALESCE(p.codigo, sr.codigo_origen) AS ing_codigo"),
                    'ri.cantidad_por_plato as cantidad',
                    'ri.unidad',
                ])
                ->get()
                ->groupBy('receta_codigo');

            $candidatosSet = array_flip($codigosCandidatos);

            $rows = $rows->map(function ($r) use ($briloIngMapDeep, $sistemaIngMapDeep, $candidatosSet) {
                if (!isset($candidatosSet[$r['codigo']])) return $r;

                $bIngs = ($briloIngMapDeep->get($r['codigo']) ?? collect())->keyBy('ingrediente_codigo');
                $sIngs = ($sistemaIngMapDeep->get($r['codigo']) ?? collect())->keyBy('ing_codigo');
                $allCods = $bIngs->keys()->merge($sIngs->keys())->unique();

                foreach ($allCods as $cod) {
                    $b = $bIngs->get($cod);
                    $s = $sIngs->get($cod);
                    if (!$b || !$s) { $r['hay_diferencia'] = true; $r['diferencias'][] = 'ingredientes'; return $r; }

                    $bCant = (float) ($b->cantidad_pres > 0 ? $b->cantidad_pres : $b->cantidad_base);
                    $bUnit = ($b->cantidad_pres > 0 && $b->unidad_pres) ? $b->unidad_pres : $b->unidad_base;

                    if ($this->cantidadesDifieren($bCant, (string) $bUnit, (float) $s->cantidad, (string) $s->unidad)) {
                        $r['hay_diferencia'] = true;
                        $r['diferencias'][] = 'ingredientes';
                        return $r;
                    }
                }
                return $r;
            });
        }

        // Categorías válidas: las que existen en nuestro catálogo (receta_categorias)
        // Solo Platos/Bebidas/Sub para coincidir con el CatalogoRecetas
        $categoriasValidas = DB::connection('compras')
            ->table('receta_categorias')
            ->whereRaw("LOWER(nombre) LIKE 'platos%' OR LOWER(nombre) LIKE 'bebidas%' OR LOWER(nombre) LIKE 'sub%'")
            ->pluck('nombre')
            ->map(fn($n) => strtolower(trim($n)))
            ->flip()
            ->all(); // lookup O(1): ['platos fuertes' => 0, 'bebidas con alcohol' => 1, ...]

        // Códigos que existen en productos (materias primas): si Brilo los tiene como receta
        // pero en nuestro sistema están registrados como productos, no los marcamos como "Solo en BRILO"
        $codigosEnProductos = DB::connection('compras')
            ->table('productos')
            ->whereNotNull('codigo')
            ->pluck('codigo')
            ->flip()
            ->all();

        $rows = $rows->filter(function ($r) use ($categoriasValidas, $codigosEnProductos) {
            $scat = strtolower(trim($r['sistema_categoria'] ?? ''));
            $bcat = strtolower(trim($r['brilo_categoria']  ?? ''));
            // En nuestro sistema: solo si tiene una categoría válida del catálogo
            if (!$r['solo_en_brilo'] && isset($categoriasValidas[$scat])) return true;
            // Solo en Brilo: si el código existe en productos, ya está en el sistema → omitir
            if ($r['solo_en_brilo'] && isset($codigosEnProductos[$r['codigo']])) return false;
            // Solo en Brilo: categoría válida Y activo en Brilo (excluye versiones viejas inactivas)
            if ($r['solo_en_brilo'] && isset($categoriasValidas[$bcat]) && $r['brilo_activo'] === true) return true;
            return false;
        });

        // Categorías del dropdown: las que tienen recetas en los resultados actuales
        $categorias = $rows
            ->map(fn ($r) => $r['sistema_categoria'])
            ->filter()
            ->unique()
            ->sort()
            ->values();

        // Filtro de categoría (antes del resumen para que los stats reflejen la categoría seleccionada)
        if ($categoria) {
            $rows = $rows->filter(fn ($r) =>
                ($r['sistema_categoria'] ?? '') === $categoria
            );
        }

        // Resumen GLOBAL de la categoría seleccionada (o todo si no hay filtro)
        $resumen = [
            'total'           => $rows->count(),
            'solo_en_brilo'   => $rows->filter(fn ($r) => $r['solo_en_brilo'])->count(),
            'solo_en_sistema' => $rows->filter(fn ($r) => $r['solo_en_sistema'])->count(),
            'con_diferencias' => $rows->filter(fn ($r) => $r['hay_diferencia'] && !$r['solo_en_brilo'] && !$r['solo_en_sistema'])->count(),
            'sincronizados'   => $rows->filter(fn ($r) => !$r['hay_diferencia'])->count(),
            'categorias'      => $categorias,
        ];

        // Filtros de estado (después del resumen)
        if ($filtro === 'diferencias')  $rows = $rows->filter(fn ($r) => $r['hay_diferencia']);
        if ($filtro === 'solo_brilo')   $rows = $rows->filter(fn ($r) => $r['solo_en_brilo']);
        if ($filtro === 'solo_sistema') $rows = $rows->filter(fn ($r) => $r['solo_en_sistema']);
        if ($tipo)                      $rows = $rows->filter(fn ($r) => $r['tipo_receta'] === $tipo);
        if ($estado)                    $rows = $rows->filter(fn ($r) => ($r['sistema_estado'] ?? '') === $estado);
        if ($buscar !== '') {
            $buscarLow = strtolower($buscar);
            $rows = $rows->filter(fn ($r) =>
                str_contains(strtolower($r['codigo'] ?? ''), $buscarLow) ||
                str_contains(strtolower($r['brilo_nombre'] ?? ''), $buscarLow) ||
                str_contains(strtolower($r['sistema_nombre'] ?? ''), $buscarLow)
            );
        }

        $rows  = $rows->values();
        $total = $rows->count();
        $items = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data'         => $items,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => max(1, (int) ceil($total / $perPage)),
            'resumen'      => $resumen,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    public function detalle(string $codigo)
    {
        $this->requireAdminRecetas();

        $codigo = strtoupper(trim($codigo));

        $briloIngs = DB::connection('compras')
            ->table('brilo_snapshot_ingredientes')
            ->where('receta_codigo', $codigo)
            ->where('activo', true)
            ->orderBy('ingrediente_codigo')
            ->get()
            ->keyBy('ingrediente_codigo');

        $receta = DB::connection('compras')
            ->table('recetas')
            ->where('codigo_origen', $codigo)
            ->first();

        $sistemaIngs = collect();
        if ($receta) {
            $sistemaIngs = DB::connection('compras')
                ->table('receta_ingredientes as ri')
                ->leftJoin('productos as p',  'ri.producto_id',   '=', 'p.id')
                ->leftJoin('recetas as sr',   'ri.sub_receta_id', '=', 'sr.id')
                ->where('ri.receta_id', $receta->id)
                ->select([
                    DB::raw("COALESCE(p.codigo, sr.codigo_origen) AS codigo"),
                    DB::raw("COALESCE(p.nombre, sr.nombre) AS nombre"),
                    'ri.cantidad_por_plato as cantidad',
                    'ri.unidad',
                    DB::raw('(sr.id IS NOT NULL) AS es_sub_receta'),
                ])
                ->orderBy('codigo')
                ->get()
                ->keyBy('codigo');
        }

        $allCods = $briloIngs->keys()->merge($sistemaIngs->keys())->unique();

        $comparativa = $allCods->map(function ($cod) use ($briloIngs, $sistemaIngs) {
            $b = $briloIngs->get($cod);
            $s = $sistemaIngs->get($cod);

            $bCant = $b ? ($b->cantidad_pres > 0 ? (float) $b->cantidad_pres : (float) $b->cantidad_base) : null;
            $bUnit = $b ? ($b->cantidad_pres > 0 && $b->unidad_pres ? $b->unidad_pres : $b->unidad_base) : null;
            $sCant = $s ? (float) $s->cantidad : null;
            $sUnit = $s ? $s->unidad : null;

            $cantDif = $bCant !== null && $sCant !== null && $this->cantidadesDifieren($bCant, $bUnit ?? 'u', $sCant, $sUnit ?? 'u');
            // Marcar unidad diferente solo si las cantidades son realmente distintas tras conversión
            // (si 0.0156 GALON = 2 oz fl, no hay diferencia de unidad que importa)
            $unitDif = $cantDif && $bUnit && $sUnit && strtolower(trim($bUnit)) !== strtolower(trim($sUnit))
                && $this->normalizarCantidad(1, $bUnit)['family'] !== $this->normalizarCantidad(1, $sUnit)['family'];

            return [
                'codigo'           => $cod,
                'nombre'           => $b?->ingrediente_nombre ?? $s?->nombre,
                'es_sub_receta'    => $b ? $this->boolVal($b->es_sub_receta) : (bool) ($s?->es_sub_receta ?? false),
                'brilo_cant_base'  => $b ? (float) $b->cantidad_base : null,
                'brilo_unit_base'  => $b?->unidad_base,
                'brilo_cant_pres'  => $b && $b->cantidad_pres > 0 ? (float) $b->cantidad_pres : null,
                'brilo_unit_pres'  => $b?->unidad_pres,
                'sistema_cant'     => $sCant,
                'sistema_unit'     => $sUnit,
                'solo_en_brilo'    => $b && !$s,
                'solo_en_sistema'  => !$b && $s,
                'cant_diferente'   => $cantDif,
                'unidad_diferente' => $unitDif,
                'hay_diferencia'   => ($b && !$s) || (!$b && $s) || $cantDif || $unitDif,
            ];
        })->sortBy('codigo')->values();

        return response()->json([
            'codigo'      => $codigo,
            'comparativa' => $comparativa,
            'resumen'     => [
                'total'          => $comparativa->count(),
                'solo_brilo'     => $comparativa->filter(fn ($r) => $r['solo_en_brilo'])->count(),
                'solo_sistema'   => $comparativa->filter(fn ($r) => $r['solo_en_sistema'])->count(),
                'cant_diferente' => $comparativa->filter(fn ($r) => $r['cant_diferente'])->count(),
                'unit_diferente' => $comparativa->filter(fn ($r) => $r['unidad_diferente'])->count(),
                'iguales'        => $comparativa->filter(fn ($r) => !$r['hay_diferencia'])->count(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    public function historial(string $codigo)
    {
        $this->requireAdminRecetas();

        $receta = DB::connection('compras')
            ->table('recetas')
            ->where('codigo_origen', strtoupper(trim($codigo)))
            ->first();

        if (!$receta) {
            return response()->json(['data' => [], 'mensaje' => 'Receta no encontrada en nuestro sistema']);
        }

        $rows = DB::connection('compras')
            ->table('aud_receta_ingredientes as a')
            ->leftJoin('productos as p',  'a.producto_id',   '=', 'p.id')
            ->leftJoin('recetas as sr',   'a.sub_receta_id', '=', 'sr.id')
            ->where('a.receta_id', $receta->id)
            ->select([
                'a.aud_id',
                'a.accion',
                DB::raw("a.fecha_accion AT TIME ZONE 'America/El_Salvador' AS fecha_sv"),
                'a.aud_usuario',
                DB::raw("COALESCE(p.codigo, sr.codigo_origen) AS ing_codigo"),
                DB::raw("COALESCE(p.nombre, sr.nombre) AS ing_nombre"),
                'a.cantidad_por_plato',
                'a.unidad',
            ])
            ->orderBy('a.fecha_accion', 'desc')
            ->limit(500)
            ->get();

        return response()->json(['data' => $rows]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function normalizarCantidad(float $cantidad, string $unidad): array
    {
        $u = strtolower(trim($unidad));

        static $peso = [
            'libra' => 453.592, 'lb' => 453.592,
            'oz' => 28.3495, 'onza' => 28.3495, 'onzas' => 28.3495,
            'gramo' => 1, 'g' => 1, 'gr' => 1,
            'kg' => 1000, 'kilogramo' => 1000, 'kilo' => 1000,
        ];
        static $volumen = [
            'galon' => 3785.41, 'galón' => 3785.41, 'gal' => 3785.41,
            'litro' => 1000, 'l' => 1000,
            'ml' => 1, 'mililitro' => 1,
            'oz fl' => 29.5735, 'oz. fl.' => 29.5735, 'oz.fl' => 29.5735, 'fl oz' => 29.5735,
        ];

        if (isset($peso[$u]))    return ['family' => 'peso',    'value' => $cantidad * $peso[$u]];
        if (isset($volumen[$u])) return ['family' => 'volumen', 'value' => $cantidad * $volumen[$u]];
        return ['family' => $u,  'value' => $cantidad]; // sin conversión conocida
    }

    private function cantidadesDifieren(float $bCant, string $bUnit, float $sCant, string $sUnit): bool
    {
        $bN = $this->normalizarCantidad($bCant, $bUnit);
        $sN = $this->normalizarCantidad($sCant, $sUnit);

        if ($bN['family'] !== $sN['family']) return true; // familias incompatibles

        $tolerancia = 0.02 * max($bN['value'], $sN['value'], 0.001); // 2% de tolerancia por redondeos
        return abs($bN['value'] - $sN['value']) > $tolerancia;
    }

    private function boolVal($v): bool
    {
        if (is_bool($v)) return $v;
        if ($v === 't' || $v === '1' || $v === 1) return true;
        if ($v === 'f' || $v === '0' || $v === 0) return false;
        return (bool) $v;
    }

    private function requireAdminRecetas(): void
    {
        $user  = \Illuminate\Support\Facades\Auth::user();
        $roles = $user ? $user->roles()->pluck('codigo')->toArray() : [];
        if (!array_intersect(['admin_compras', 'admin_recetas'], $roles)) {
            abort(403, 'No autorizado.');
        }
    }
}
