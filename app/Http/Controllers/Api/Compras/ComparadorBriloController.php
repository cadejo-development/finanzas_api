<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Comparativa Brilo ↔ Sistema propio.
 *
 * GET /api/compras/comparador-brilo                  → lista de recetas con estado comparativo
 * GET /api/compras/comparador-brilo/{codigo}/detalle → ingredientes comparados lado a lado
 * GET /api/compras/comparador-brilo/{codigo}/historial → historial de cambios (aud_receta_ingredientes)
 */
class ComparadorBriloController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/comparador-brilo
    // ─────────────────────────────────────────────────────────────────────────
    public function index(Request $request)
    {
        $this->requireAdminRecetas();

        $filtro   = $request->query('filtro', 'todos');   // todos | diferencias | solo_brilo | solo_sistema
        $buscar   = trim($request->query('buscar', ''));
        $tipo     = $request->query('tipo');              // plato | sub_receta
        $perPage  = min((int) $request->query('per_page', 50), 200);

        // Snapshot Brilo
        $brilo = DB::connection('compras')
            ->table('brilo_snapshot_recetas as b')
            ->select([
                'b.codigo', 'b.nombre as brilo_nombre', 'b.tipo_receta as brilo_tipo',
                'b.categoria_nombre as brilo_categoria',
                'b.precio as brilo_precio', 'b.activo as brilo_activo',
                'b.no_enviar_cocina', 'b.en_boton_cocina',
                'b.sucursales as brilo_sucursales',
                'b.synced_at',
                // Contar ingredientes en Brilo
                DB::raw('(SELECT COUNT(*) FROM brilo_snapshot_ingredientes bi WHERE bi.receta_codigo = b.codigo AND bi.activo = true) AS brilo_num_ing'),
            ]);

        // Nuestro sistema
        $sistema = DB::connection('compras')
            ->table('recetas as r')
            ->leftJoin('receta_categorias as rc', 'r.categoria_id', '=', 'rc.id')
            ->whereNotNull('r.codigo_origen')->where('r.codigo_origen', '!=', '')
            ->select([
                'r.codigo_origen as codigo',
                'r.nombre as sistema_nombre',
                'r.tipo_receta as sistema_tipo',
                'rc.nombre as sistema_categoria',
                'r.activa as sistema_activa',
                'r.modificado_localmente',
                'r.sincronizado_brilo',
                DB::raw('(SELECT COUNT(*) FROM receta_ingredientes ri WHERE ri.receta_id = r.id) AS sistema_num_ing'),
            ]);

        // Hacer el FULL OUTER JOIN en PHP (simple y portable)
        $briloRows   = $brilo->get()->keyBy('codigo');
        $sistemaRows = $sistema->get()->keyBy('codigo');

        $allCodigos = $briloRows->keys()->merge($sistemaRows->keys())->unique();

        $rows = $allCodigos->map(function ($cod) use ($briloRows, $sistemaRows) {
            $b = $briloRows->get($cod);
            $s = $sistemaRows->get($cod);

            $soloEnBrilo   = $b && !$s;
            $soloEnSistema = !$b && $s;
            $enAmbos       = $b && $s;

            $nombreDiferente   = $enAmbos && strtolower(trim($b->brilo_nombre ?? '')) !== strtolower(trim($s->sistema_nombre ?? ''));
            $activoDiferente   = $enAmbos && (bool) $b->brilo_activo !== (bool) $s->sistema_activa;
            $numIngDiferente   = $enAmbos && (int) $b->brilo_num_ing !== (int) $s->sistema_num_ing;
            $hayDiferencia     = $soloEnBrilo || $soloEnSistema || $nombreDiferente || $activoDiferente || $numIngDiferente;

            return (object) [
                'codigo'              => $cod,
                'brilo_nombre'        => $b?->brilo_nombre,
                'sistema_nombre'      => $s?->sistema_nombre,
                'tipo_receta'         => $b?->brilo_tipo ?? $s?->sistema_tipo,
                'brilo_categoria'     => $b?->brilo_categoria,
                'sistema_categoria'   => $s?->sistema_categoria,
                'brilo_precio'        => $b?->brilo_precio,
                'brilo_activo'        => $b ? (bool) $b->brilo_activo : null,
                'sistema_activa'      => $s ? (bool) $s->sistema_activa : null,
                'no_enviar_cocina'    => $b ? (bool) $b->no_enviar_cocina : null,
                'en_boton_cocina'     => $b ? (bool) $b->en_boton_cocina : null,
                'brilo_sucursales'    => $b?->brilo_sucursales ? json_decode($b->brilo_sucursales) : [],
                'brilo_num_ing'       => $b ? (int) $b->brilo_num_ing : null,
                'sistema_num_ing'     => $s ? (int) $s->sistema_num_ing : null,
                'modificado_local'    => $s ? (bool) $s->modificado_localmente : null,
                'sincronizado_brilo'  => $s ? (bool) $s->sincronizado_brilo : null,
                'solo_en_brilo'       => $soloEnBrilo,
                'solo_en_sistema'     => $soloEnSistema,
                'hay_diferencia'      => $hayDiferencia,
                'diferencias'         => array_filter([
                    $soloEnBrilo    ? 'solo_en_brilo'    : null,
                    $soloEnSistema  ? 'solo_en_sistema'  : null,
                    $nombreDiferente   ? 'nombre'         : null,
                    $activoDiferente   ? 'estado'         : null,
                    $numIngDiferente   ? 'ingredientes'   : null,
                ]),
                'synced_at' => $b?->synced_at,
            ];
        })->values();

        // Filtros
        if ($filtro === 'diferencias')  $rows = $rows->filter(fn ($r) => $r->hay_diferencia);
        if ($filtro === 'solo_brilo')   $rows = $rows->filter(fn ($r) => $r->solo_en_brilo);
        if ($filtro === 'solo_sistema') $rows = $rows->filter(fn ($r) => $r->solo_en_sistema);
        if ($tipo)                      $rows = $rows->filter(fn ($r) => $r->tipo_receta === $tipo);
        if ($buscar !== '') {
            $rows = $rows->filter(fn ($r) =>
                str_contains(strtolower($r->codigo), strtolower($buscar)) ||
                str_contains(strtolower($r->brilo_nombre ?? ''), strtolower($buscar)) ||
                str_contains(strtolower($r->sistema_nombre ?? ''), strtolower($buscar))
            );
        }

        $rows = $rows->sortBy('codigo')->values();
        $total = $rows->count();
        $page  = max(1, (int) $request->query('page', 1));
        $items = $rows->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data'         => $items,
            'total'        => $total,
            'per_page'     => $perPage,
            'current_page' => $page,
            'last_page'    => (int) ceil($total / $perPage),
            'resumen'      => [
                'total'            => $rows->count(),
                'solo_en_brilo'    => $rows->filter(fn ($r) => $r->solo_en_brilo)->count(),
                'solo_en_sistema'  => $rows->filter(fn ($r) => $r->solo_en_sistema)->count(),
                'con_diferencias'  => $rows->filter(fn ($r) => $r->hay_diferencia && !$r->solo_en_brilo && !$r->solo_en_sistema)->count(),
                'sincronizados'    => $rows->filter(fn ($r) => !$r->hay_diferencia)->count(),
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/comparador-brilo/{codigo}/detalle
    // ─────────────────────────────────────────────────────────────────────────
    public function detalle(string $codigo)
    {
        $this->requireAdminRecetas();

        $codigo = strtoupper(trim($codigo));

        // Ingredientes Brilo
        $briloIngs = DB::connection('compras')
            ->table('brilo_snapshot_ingredientes')
            ->where('receta_codigo', $codigo)
            ->where('activo', true)
            ->orderBy('ingrediente_codigo')
            ->get()
            ->keyBy('ingrediente_codigo');

        // Receta en nuestro sistema
        $receta = DB::connection('compras')
            ->table('recetas as r')
            ->where('r.codigo_origen', $codigo)
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

            // Cantidad efectiva de Brilo: presentación si existe, si no base
            $bCant  = $b ? ($b->cantidad_pres > 0 ? (float) $b->cantidad_pres : (float) $b->cantidad_base) : null;
            $bUnit  = $b ? ($b->cantidad_pres > 0 && $b->unidad_pres ? $b->unidad_pres : $b->unidad_base) : null;
            $sCant  = $s ? (float) $s->cantidad : null;
            $sUnit  = $s ? $s->unidad : null;

            $cantDif = $bCant !== null && $sCant !== null && abs($bCant - $sCant) > 0.0001;
            $unitDif = $bUnit && $sUnit && strtolower(trim($bUnit)) !== strtolower(trim($sUnit));

            return [
                'codigo'            => $cod,
                'nombre'            => $b?->ingrediente_nombre ?? $s?->nombre,
                'es_sub_receta'     => $b ? (bool) $b->es_sub_receta : (bool) ($s?->es_sub_receta ?? false),
                'brilo_cant_base'   => $b ? (float) $b->cantidad_base : null,
                'brilo_unit_base'   => $b?->unidad_base,
                'brilo_cant_pres'   => $b && $b->cantidad_pres > 0 ? (float) $b->cantidad_pres : null,
                'brilo_unit_pres'   => $b?->unidad_pres,
                'sistema_cant'      => $sCant,
                'sistema_unit'      => $sUnit,
                'solo_en_brilo'     => $b && !$s,
                'solo_en_sistema'   => !$b && $s,
                'cant_diferente'    => $cantDif,
                'unidad_diferente'  => $unitDif,
                'hay_diferencia'    => $b && !$s || !$b && $s || $cantDif || $unitDif,
            ];
        })->values()->sortBy('codigo')->values();

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
    // GET /api/compras/comparador-brilo/{codigo}/historial
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

    private function requireAdminRecetas(): void
    {
        $user  = \Illuminate\Support\Facades\Auth::user();
        $roles = $user ? $user->roles()->pluck('codigo')->toArray() : [];
        if (!array_intersect(['admin_compras', 'admin_recetas'], $roles)) {
            abort(403, 'No autorizado.');
        }
    }
}
