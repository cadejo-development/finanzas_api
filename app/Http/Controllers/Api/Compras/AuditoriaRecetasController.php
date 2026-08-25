<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Mail\Compras\AuditoriaCalidadNotificacion;
use App\Models\AuditoriaFoto;
use App\Models\AuditoriaItem;
use App\Models\AuditoriaCriterio;
use App\Models\AuditoriaReceta;
use App\Models\AuditoriaRecetaItem;
use App\Models\AuditoriaRecetaCriterio;
use App\Models\Empleado;
use App\Models\Estacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class AuditoriaRecetasController extends Controller
{
    // ── GET /api/compras/auditorias ──────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $perPage    = min((int) $request->query('per_page', 20), 100);
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;
        $desde      = $request->query('desde');
        $hasta      = $request->query('hasta');
        $recetaId   = $request->query('receta_id') ? (int) $request->query('receta_id') : null;
        $evaluadorId = $request->query('evaluador_id') ? (int) $request->query('evaluador_id') : null;

        $tipo = $request->query('tipo');

        $query = AuditoriaReceta::with(['estacion', 'receta', 'fotos'])
            ->withCount('recetaItems')
            ->orderByDesc('fecha')
            ->orderByDesc('hora');

        if ($tipo)        $query->where('tipo', $tipo);
        if ($sucursalId)  $query->where('sucursal_id', $sucursalId);
        if ($desde)       $query->whereDate('fecha', '>=', $desde);
        if ($hasta)       $query->whereDate('fecha', '<=', $hasta);
        if ($recetaId)    $query->where('receta_id', $recetaId);
        if ($evaluadorId) $query->where('evaluador_id', $evaluadorId);

        $estado       = $request->query('estado');
        $clasificacion = $request->query('clasificacion');
        if ($estado)        $query->where('estado', $estado);
        if ($clasificacion) $query->where('clasificacion', $clasificacion);

        $paginated = $query->paginate($perPage);

        // Obtener nombres de sucursales en batch desde pgsql
        $sucursalIds = $paginated->getCollection()->pluck('sucursal_id')->unique()->filter()->values()->toArray();
        $sucursales  = $sucursalIds
            ? DB::connection('pgsql')->table('sucursales')->whereIn('id', $sucursalIds)->pluck('nombre', 'id')
            : collect();

        $items = $paginated->getCollection()->map(fn ($a) => $this->formatAuditoria($a, $sucursales));

        return response()->json([
            'data'         => $items,
            'current_page' => $paginated->currentPage(),
            'last_page'    => $paginated->lastPage(),
            'total'        => $paginated->total(),
        ]);
    }

    // ── POST /api/compras/auditorias ─────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tipo'               => 'nullable|in:operaciones,calidad',
            'fecha'              => 'required|date',
            'hora'               => 'required|date_format:H:i,H:i:s',
            'sucursal_id'        => 'required|integer',
            'estacion_id'        => 'nullable|integer|exists:compras.estaciones,id',
            'receta_id'          => 'nullable|integer|exists:compras.recetas,id',
            'tipo_receta'        => 'nullable|in:plato,sub_receta',
            'responsable_id'     => 'nullable|integer',
            'responsable_nombre' => 'nullable|string|max:200',
            'notas'              => 'nullable|string',
            'fotos'              => 'nullable|array',
            'fotos.*'            => 'string|max:1000',
        ]);

        $usuario = $request->user()?->email ?? 'sistema';
        $user    = $request->user();

        $tipoAuditoria = $validated['tipo'] ?? 'operaciones';
        $estadoInicial = 'borrador';

        $esMultiReceta = empty($validated['receta_id']);

        $auditoria = DB::connection('compras')->transaction(function () use ($validated, $usuario, $user, $tipoAuditoria, $estadoInicial, $esMultiReceta): AuditoriaReceta {
            $auditoria = AuditoriaReceta::create([
                'tipo'               => $tipoAuditoria,
                'fecha'              => $validated['fecha'],
                'hora'               => $validated['hora'],
                'sucursal_id'        => $validated['sucursal_id'],
                'estacion_id'        => $validated['estacion_id'] ?? null,
                'receta_id'          => $validated['receta_id'] ?? null,
                'tipo_receta'        => $validated['tipo_receta'] ?? 'plato',
                'es_multi_receta'    => $esMultiReceta,
                'responsable_id'     => $validated['responsable_id'] ?? null,
                'responsable_nombre' => $validated['responsable_nombre'] ?? null,
                'evaluador_id'       => $user?->id,
                'evaluador_nombre'   => $user?->name ?? $usuario,
                'notas'              => $validated['notas'] ?? null,
                'estado'             => $estadoInicial,
                'aud_usuario'        => $usuario,
            ]);

            foreach ($validated['fotos'] ?? [] as $idx => $url) {
                AuditoriaFoto::create([
                    'auditoria_id' => $auditoria->id,
                    'url'          => $url,
                    'orden'        => $idx,
                ]);
            }

            return $auditoria;
        });

        $auditoria->load(['estacion', 'receta', 'fotos']);
        $sucursales = DB::connection('pgsql')->table('sucursales')
            ->where('id', $auditoria->sucursal_id)->pluck('nombre', 'id');

        return response()->json(['data' => $this->formatAuditoria($auditoria, $sucursales)], 201);
    }

    // ── GET /api/compras/auditorias/{id} ─────────────────────────────
    public function show(int $id): JsonResponse
    {
        $auditoria  = AuditoriaReceta::with([
            'estacion', 'receta', 'fotos',
            'recetaItems.receta', 'recetaItems.estacion',
        ])->findOrFail($id);
        $sucursales = DB::connection('pgsql')->table('sucursales')
            ->where('id', $auditoria->sucursal_id)->pluck('nombre', 'id');

        return response()->json(['data' => $this->formatAuditoria($auditoria, $sucursales)]);
    }

    // ── POST /api/compras/auditorias/{id}/recetas ────────────────────
    public function addRecetaItem(Request $request, int $id): JsonResponse
    {
        $auditoria = AuditoriaReceta::findOrFail($id);

        if ($auditoria->receta_id !== null) {
            return response()->json(['message' => 'Esta es una auditoría de formato antiguo (receta única). No admite recetas adicionales.'], 422);
        }

        $validated = $request->validate([
            'receta_id'          => 'required|integer|exists:compras.recetas,id',
            'tipo_receta'        => 'nullable|in:plato,sub_receta',
            'estacion_id'        => 'nullable|integer|exists:compras.estaciones,id',
            'responsable_id'     => 'nullable|integer',
            'responsable_nombre' => 'nullable|string|max:200',
            'notas'              => 'nullable|string|max:2000',
        ]);

        $orden = AuditoriaRecetaItem::where('auditoria_id', $auditoria->id)->count();

        $item = AuditoriaRecetaItem::create([
            'auditoria_id'       => $auditoria->id,
            'receta_id'          => $validated['receta_id'],
            'tipo_receta'        => $validated['tipo_receta'] ?? 'plato',
            'estacion_id'        => $validated['estacion_id'] ?? null,
            'responsable_id'     => $validated['responsable_id'] ?? null,
            'responsable_nombre' => $validated['responsable_nombre'] ?? null,
            'notas'              => $validated['notas'] ?? null,
            'orden'              => $orden,
        ]);

        $item->load(['receta', 'estacion']);

        return response()->json(['data' => $this->formatRecetaItem($item)], 201);
    }

    // ── PUT /api/compras/auditorias/{id}/recetas/{itemId} ────────────
    public function updateRecetaItem(Request $request, int $id, int $itemId): JsonResponse
    {
        $item = AuditoriaRecetaItem::where('auditoria_id', $id)->findOrFail($itemId);

        $validated = $request->validate([
            'estacion_id'        => 'nullable|integer|exists:compras.estaciones,id',
            'responsable_id'     => 'nullable|integer',
            'responsable_nombre' => 'nullable|string|max:200',
            'notas'              => 'nullable|string|max:2000',
        ]);

        $item->update($validated);
        $item->load(['receta', 'estacion']);

        return response()->json(['data' => $this->formatRecetaItem($item)]);
    }

    // ── DELETE /api/compras/auditorias/{id}/recetas/{itemId} ─────────
    public function removeRecetaItem(int $id, int $itemId): JsonResponse
    {
        $item = AuditoriaRecetaItem::where('auditoria_id', $id)->findOrFail($itemId);

        DB::connection('compras')->transaction(function () use ($item, $id) {
            // Borrar fotos de sección de este recipe item
            AuditoriaFoto::where('auditoria_id', $id)
                ->where('receta_item_id', $item->id)
                ->delete();

            // Borrar auditoria_items de este recipe item
            AuditoriaItem::where('auditoria_id', $id)
                ->where('receta_item_id', $item->id)
                ->delete();

            $item->delete();

            // Recalcular score global
            $this->recalcularScoreGlobal($id);
        });

        return response()->json(['message' => 'Receta eliminada de la auditoría.']);
    }

    // ── GET /api/compras/auditorias/{id}/recetas/{itemId}/items ──────
    public function itemsRecetaShow(int $id, int $itemId): JsonResponse
    {
        AuditoriaReceta::findOrFail($id);
        AuditoriaRecetaItem::where('auditoria_id', $id)->findOrFail($itemId);

        $items = AuditoriaItem::where('auditoria_id', $id)
            ->where('receta_item_id', $itemId)
            ->with('criterio')
            ->get()
            ->map(fn ($i) => [
                'criterio_id'   => $i->criterio_id,
                'categoria'     => $i->criterio?->categoria,
                'nombre'        => $i->criterio?->nombre,
                'resultado'     => $i->resultado,
                'observaciones' => $i->observaciones,
                'foto_url'      => $i->foto_url ? $this->presignS3Url($i->foto_url) : null,
            ]);

        return response()->json(['data' => $items]);
    }

    // ── POST /api/compras/auditorias/{id}/recetas/{itemId}/items ─────
    public function itemsRecetaSave(Request $request, int $id, int $itemId): JsonResponse
    {
        $auditoria  = AuditoriaReceta::findOrFail($id);
        $recetaItem = AuditoriaRecetaItem::where('auditoria_id', $id)->findOrFail($itemId);

        $validated = $request->validate([
            'items'                 => 'required|array',
            'items.*.criterio_id'   => 'required|integer|exists:compras.auditoria_criterios,id',
            'items.*.resultado'     => 'nullable|in:cumple,no_cumple,na',
            'items.*.observaciones' => 'nullable|string|max:500',
            'items.*.foto_url'      => 'nullable|string|max:1000',
            'secciones_fotos'       => 'nullable|array',
            'secciones_fotos.*'     => 'array',
            'secciones_fotos.*.*'   => 'string|max:1000',
        ]);

        DB::connection('compras')->transaction(function () use ($auditoria, $recetaItem, $validated, $id, $itemId) {
            // Guardar items con receta_item_id
            foreach ($validated['items'] as $item) {
                AuditoriaItem::updateOrCreate(
                    ['auditoria_id' => $id, 'criterio_id' => $item['criterio_id'], 'receta_item_id' => $itemId],
                    [
                        'resultado'     => $item['resultado'] ?? null,
                        'observaciones' => $item['observaciones'] ?? null,
                        'foto_url'      => $item['foto_url'] ?? null,
                    ]
                );
            }

            // Fotos por sección de esta receta: descripcion = 'ri:{itemId}:sec:NombreSeccion'
            if (!empty($validated['secciones_fotos'])) {
                $prefix = "ri:{$itemId}:sec:";
                $seccionNames = array_map(fn ($s) => $prefix . $s, array_keys($validated['secciones_fotos']));
                AuditoriaFoto::where('auditoria_id', $id)
                    ->where('receta_item_id', $itemId)
                    ->whereIn('descripcion', $seccionNames)
                    ->delete();

                foreach ($validated['secciones_fotos'] as $seccion => $urls) {
                    foreach ($urls as $idx => $url) {
                        AuditoriaFoto::create([
                            'auditoria_id'  => $id,
                            'receta_item_id' => $itemId,
                            'url'           => $url,
                            'descripcion'   => $prefix . $seccion,
                            'orden'         => $idx,
                        ]);
                    }
                }
            }

            // Calcular calificación del recipe item
            // Fórmula: cumplePeso / (totalMax - naPeso) × 100
            $criterioIds = collect($validated['items'])->pluck('criterio_id')->filter()->all();
            $pesos = AuditoriaCriterio::whereIn('id', $criterioIds)
                ->get(['id', 'peso'])
                ->keyBy('id');

            $totalMax = $naPeso = $pesoObtenido = $evaluados = 0;
            foreach ($validated['items'] as $item) {
                $resultado = $item['resultado'] ?? null;
                $peso = $pesos[$item['criterio_id']]?->peso ?? 1;
                $totalMax += $peso;
                if ($resultado === 'na')     { $naPeso += $peso; continue; }
                if (!$resultado)             continue;
                if ($resultado === 'cumple') $pesoObtenido += $peso;
                $evaluados++;
            }

            $denominador  = $totalMax - $naPeso;
            $calificacion = ($evaluados > 0 && $denominador > 0)
                ? round(($pesoObtenido / $denominador) * 100, 1)
                : null;
            $clasificacion = $this->calcularClasificacion($calificacion, $auditoria->tipo);

            $recetaItem->update([
                'calificacion'  => $calificacion,
                'clasificacion' => $clasificacion,
            ]);

            // Recalcular score global de la auditoría
            $this->recalcularScoreGlobal($id);
        });

        $recetaItem->refresh();

        return response()->json([
            'calificacion'  => $recetaItem->calificacion,
            'clasificacion' => $recetaItem->clasificacion,
        ]);
    }

    // ── DELETE /api/compras/auditorias/{id} ──────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $auditoria = AuditoriaReceta::findOrFail($id);

        if ($auditoria->estado !== 'borrador') {
            return response()->json(['message' => 'Solo se pueden eliminar auditorías en estado borrador.'], 422);
        }

        $auditoria->fotos()->delete();
        $auditoria->delete();
        return response()->json(['message' => 'Auditoría eliminada.']);
    }

    // ── GET /api/compras/auditorias/dashboard ────────────────────────
    public function dashboard(Request $request): JsonResponse
    {
        $sucursalId  = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;
        $sucursalIds = $request->query('sucursal_ids') ? array_map('intval', (array) $request->query('sucursal_ids')) : null;
        $desde       = $request->query('desde', now()->subDays(30)->toDateString());
        $hasta       = $request->query('hasta', now()->toDateString());
        $tipo        = $request->query('tipo');

        $base = DB::connection('compras')->table('auditorias_receta')
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta);

        if ($tipo)              $base->where('tipo', $tipo);
        if ($sucursalId)        $base->where('sucursal_id', $sucursalId);
        elseif ($sucursalIds)   $base->whereIn('sucursal_id', $sucursalIds);

        // Totales
        $total       = (clone $base)->count();
        $conFotos    = (clone $base)->whereExists(fn ($q) =>
            $q->select(DB::raw(1))->from('auditoria_fotos')->whereColumn('auditoria_fotos.auditoria_id', 'auditorias_receta.id')
        )->count();
        $evaluadores = (clone $base)->distinct('evaluador_id')->count('evaluador_id');

        // Por sucursal
        $porSucursal = (clone $base)
            ->select(
                'sucursal_id',
                DB::raw('COUNT(*) as total'),
                DB::raw('ROUND(AVG(CAST(calificacion AS DECIMAL(5,1))), 1) as calificacion_promedio')
            )
            ->groupBy('sucursal_id')
            ->orderByDesc('total')
            ->get();

        $sucursalIds2 = $porSucursal->pluck('sucursal_id')->unique()->filter()->toArray();
        $sucursalesMap = $sucursalIds2
            ? DB::connection('pgsql')->table('sucursales')->whereIn('id', $sucursalIds2)->pluck('nombre', 'id')
            : collect();

        $porSucursalFmt = $porSucursal->map(fn ($r) => [
            'sucursal_id'         => $r->sucursal_id,
            'sucursal'            => $sucursalesMap[$r->sucursal_id] ?? "Sucursal {$r->sucursal_id}",
            'total'               => (int) $r->total,
            'calificacion_promedio' => $r->calificacion_promedio !== null ? (float) $r->calificacion_promedio : null,
        ]);

        // Por estado
        $porEstado = (clone $base)
            ->select('estado', DB::raw('COUNT(*) as total'))
            ->groupBy('estado')
            ->get()
            ->map(fn ($r) => ['estado' => $r->estado, 'total' => (int) $r->total]);

        // Por clasificación
        $porClasificacion = (clone $base)
            ->select('clasificacion', DB::raw('COUNT(*) as total'))
            ->whereNotNull('clasificacion')
            ->groupBy('clasificacion')
            ->get()
            ->map(fn ($r) => ['clasificacion' => $r->clasificacion, 'total' => (int) $r->total]);

        // Recetas más auditadas
        $topRecetas = (clone $base)
            ->select('receta_id', DB::raw('COUNT(*) as total'))
            ->whereNotNull('receta_id')
            ->groupBy('receta_id')
            ->orderByDesc('total')
            ->limit(10)
            ->get();

        $recetaIds  = $topRecetas->pluck('receta_id')->toArray();
        $recetasMap = $recetaIds
            ? DB::connection('compras')->table('recetas')->whereIn('id', $recetaIds)->pluck('nombre', 'id')
            : collect();

        $topRecetasFmt = $topRecetas->map(fn ($r) => [
            'receta_id' => $r->receta_id,
            'receta'    => $recetasMap[$r->receta_id] ?? "Receta {$r->receta_id}",
            'total'     => (int) $r->total,
        ]);

        // Tendencia diaria (últimos 30 días o rango)
        $tendencia = (clone $base)
            ->select(DB::raw('fecha::date as dia'), DB::raw('COUNT(*) as total'))
            ->groupBy(DB::raw('fecha::date'))
            ->orderBy('dia')
            ->get()
            ->map(fn ($r) => ['dia' => $r->dia, 'total' => (int) $r->total]);

        // Tendencia mensual — últimos 6 meses, promedio de calificación
        $tendenciaMensual = DB::connection('compras')->table('auditorias_receta')
            ->whereDate('fecha', '>=', now()->subMonths(5)->startOfMonth()->toDateString())
            ->whereDate('fecha', '<=', now()->endOfMonth()->toDateString())
            ->where('tipo', 'calidad')
            ->when($sucursalId,  fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->when($sucursalIds, fn ($q) => $q->whereIn('sucursal_id', $sucursalIds))
            ->whereNotNull('calificacion')
            ->selectRaw("TO_CHAR(fecha, 'YYYY-MM') as mes, ROUND(AVG(calificacion)::numeric, 1) as promedio, COUNT(*) as total")
            ->groupByRaw("TO_CHAR(fecha, 'YYYY-MM')")
            ->orderBy('mes')
            ->get()
            ->map(fn ($r) => [
                'mes'      => $r->mes,
                'promedio' => (float) $r->promedio,
                'total'    => (int)   $r->total,
            ]);

        // Sparklines por sucursal — últimos 4 meses
        $sparklines = DB::connection('compras')->table('auditorias_receta')
            ->whereDate('fecha', '>=', now()->subMonths(3)->startOfMonth()->toDateString())
            ->whereDate('fecha', '<=', now()->endOfMonth()->toDateString())
            ->where('tipo', 'calidad')
            ->whereNotNull('calificacion')
            ->whereNotNull('sucursal_id')
            ->selectRaw("sucursal_id, TO_CHAR(fecha, 'YYYY-MM') as mes, ROUND(AVG(calificacion)::numeric, 1) as promedio")
            ->groupByRaw("sucursal_id, TO_CHAR(fecha, 'YYYY-MM')")
            ->orderBy('mes')
            ->get()
            ->groupBy('sucursal_id')
            ->map(fn ($rows) => $rows->map(fn ($r) => ['mes' => $r->mes, 'promedio' => (float) $r->promedio])->values());

        // Días promedio para cerrar (respondido_at - fecha)
        $diasPromCerrarRaw = DB::connection('compras')->table('auditorias_receta')
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta)
            ->where('tipo', 'calidad')
            ->whereNotNull('respondido_at')
            ->when($sucursalId,  fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->when($sucursalIds, fn ($q) => $q->whereIn('sucursal_id', $sucursalIds))
            ->selectRaw("ROUND(AVG(DATE_PART('day', respondido_at - fecha::timestamp))::numeric, 1) as prom")
            ->value('prom');
        $diasPromCerrar = $diasPromCerrarRaw !== null ? (float) $diasPromCerrarRaw : null;

        // Cumplimiento del programa del mes (siempre sobre el mes de $hasta)
        $mesProg       = \Carbon\Carbon::parse($hasta)->startOfMonth();
        $ultimoDiaMes  = $mesProg->copy()->endOfMonth()->day;
        $periodosProg  = [
            ['desde' => $mesProg->format('Y-m') . '-01', 'hasta' => $mesProg->format('Y-m') . '-15'],
            ['desde' => $mesProg->format('Y-m') . '-16', 'hasta' => $mesProg->format('Y-m') . "-{$ultimoDiaMes}"],
        ];
        $totalSucsOp = DB::connection('pgsql')->table('sucursales as s')
            ->join('tipos_sucursal as ts', 'ts.id', '=', 's.tipo_sucursal_id')
            ->where('s.activa', true)
            ->where('ts.codigo', 'operativa')
            ->count();
        $completadasProg = 0;
        foreach ($periodosProg as $per) {
            $completadasProg += DB::connection('compras')->table('auditorias_receta')
                ->where('tipo', 'calidad')
                ->whereDate('fecha', '>=', $per['desde'])
                ->whereDate('fecha', '<=', $per['hasta'])
                ->whereIn('estado', ['pendiente_respuesta', 'respondida', 'cerrada'])
                ->distinct()
                ->count('sucursal_id');
        }
        $programadasProg  = $totalSucsOp * 2;
        $porcentajeProg   = $programadasProg > 0 ? round($completadasProg / $programadasProg * 100) : 0;
        $cumplimientoProg = [
            'completadas' => $completadasProg,
            'programadas' => $programadasProg,
            'porcentaje'  => $porcentajeProg,
            'mes_label'   => \Carbon\Carbon::parse($hasta)->locale('es')->isoFormat('MMMM YYYY'),
        ];

        // Por evaluador
        $porEvaluador = (clone $base)
            ->select('evaluador_nombre', DB::raw('COUNT(*) as total'))
            ->whereNotNull('evaluador_nombre')
            ->groupBy('evaluador_nombre')
            ->orderByDesc('total')
            ->limit(10)
            ->get()
            ->map(fn ($r) => ['evaluador' => $r->evaluador_nombre, 'total' => (int) $r->total]);

        // Últimas 5 auditorías
        $ultimas = AuditoriaReceta::with(['estacion', 'receta'])
            ->when($tipo,        fn ($q) => $q->where('tipo', $tipo))
            ->when($sucursalId,  fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->when($sucursalIds, fn ($q) => $q->whereIn('sucursal_id', $sucursalIds))
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta)
            ->orderByDesc('fecha')->orderByDesc('hora')
            ->limit(5)->get();

        $ultimasSucIds = $ultimas->pluck('sucursal_id')->unique()->filter()->toArray();
        $sucUltimas    = $ultimasSucIds
            ? DB::connection('pgsql')->table('sucursales')->whereIn('id', $ultimasSucIds)->pluck('nombre', 'id')
            : collect();

        $ultimasFmt = $ultimas->map(fn ($a) => [
            'id'                 => $a->id,
            'tipo'               => $a->tipo ?? 'operaciones',
            'fecha'              => $a->fecha?->format('Y-m-d'),
            'hora'               => substr($a->hora ?? '', 0, 5),
            'sucursal'           => $sucUltimas[$a->sucursal_id] ?? '',
            'estacion'           => $a->estacion?->nombre,
            'receta'             => $a->receta?->nombre,
            'evaluador_nombre'   => $a->evaluador_nombre,
            'responsable_nombre' => $a->responsable_nombre,
            'calificacion'       => $a->calificacion !== null ? (float) $a->calificacion : null,
            'clasificacion'      => $a->clasificacion,
            'estado'             => $a->estado,
        ]);

        // Alertas de períodos pendientes del mes actual (solo aplica cuando tipo=calidad)
        $alertasPendientes = [];
        if (!$tipo || $tipo === 'calidad') {
            $hoy       = now();
            $anio      = $hoy->year;
            $mes       = str_pad($hoy->month, 2, '0', STR_PAD_LEFT);
            $ultimoDia = $hoy->copy()->endOfMonth()->day;
            $diaHoy    = $hoy->day;

            $periodos = [
                ['label' => "1–15",              'desde' => "{$anio}-{$mes}-01", 'hasta' => "{$anio}-{$mes}-15"],
                ['label' => "16–{$ultimoDia}",   'desde' => "{$anio}-{$mes}-16", 'hasta' => "{$anio}-{$mes}-{$ultimoDia}"],
            ];
            // Solo mostrar período 2 si ya empezó (día >= 16)
            $periodosActivos = $diaHoy >= 16 ? $periodos : [$periodos[0]];

            $todasSucs = DB::connection('pgsql')->table('sucursales as s')
                ->join('tipos_sucursal as ts', 'ts.id', '=', 's.tipo_sucursal_id')
                ->where('s.activa', true)
                ->where('ts.codigo', 'operativa')
                ->orderBy('s.nombre')
                ->get(['s.id', 's.nombre']);

            foreach ($periodosActivos as $periodo) {
                $conAuditoria = DB::connection('compras')
                    ->table('auditorias_receta')
                    ->where('tipo', 'calidad')
                    ->whereDate('fecha', '>=', $periodo['desde'])
                    ->whereDate('fecha', '<=', $periodo['hasta'])
                    ->whereIn('estado', ['pendiente_respuesta', 'respondida', 'cerrada'])
                    ->distinct()
                    ->pluck('sucursal_id')
                    ->toArray();

                $faltantes = $todasSucs
                    ->filter(fn ($s) => !in_array($s->id, $conAuditoria))
                    ->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre])
                    ->values();

                if ($faltantes->isNotEmpty()) {
                    $alertasPendientes[] = [
                        'periodo'    => $periodo['label'],
                        'sucursales' => $faltantes,
                    ];
                }
            }
        }

        $porTipo = (clone $base)
            ->select('tipo', DB::raw('COUNT(*) as total'))
            ->groupBy('tipo')
            ->get()
            ->mapWithKeys(fn ($r) => [$r->tipo ?? 'operaciones' => (int) $r->total]);

        return response()->json([
            'resumen' => [
                'total'       => $total,
                'con_fotos'   => $conFotos,
                'evaluadores' => $evaluadores,
                'desde'       => $desde,
                'hasta'       => $hasta,
            ],
            'por_tipo'            => $porTipo,
            'por_sucursal'        => $porSucursalFmt,
            'por_estado'          => $porEstado,
            'por_clasificacion'   => $porClasificacion,
            'top_recetas'         => $topRecetasFmt,
            'tendencia'           => $tendencia,
            'tendencia_mensual'   => $tendenciaMensual,
            'sparklines'          => $sparklines,
            'dias_prom_cerrar'    => $diasPromCerrar,
            'cumplimiento_prog'   => $cumplimientoProg,
            'por_evaluador'       => $porEvaluador,
            'ultimas'             => $ultimasFmt,
            'alertas_pendientes'  => $alertasPendientes,
        ]);
    }

    // ── GET /api/compras/auditorias/criterios ────────────────────────
    public function criterios(Request $request): JsonResponse
    {
        $tipo = $request->query('tipo'); // 'operaciones' | 'calidad' | null (todos)

        $criterios = AuditoriaCriterio::where('activo', true)
            ->when($tipo, fn ($q) => $q->where('tipo', $tipo))
            ->orderBy('categoria_orden')
            ->orderBy('orden')
            ->get(['id', 'categoria', 'categoria_orden', 'nombre', 'peso', 'orden']);

        $grouped = $criterios->groupBy('categoria')->map(fn ($items, $cat) => [
            'categoria' => $cat,
            'items'     => $items->map(fn ($c) => [
                'id'     => $c->id,
                'nombre' => $c->nombre,
                'peso'   => $c->peso ?? 1,
            ])->values(),
        ])->values();

        return response()->json(['data' => $grouped]);
    }

    // ── GET /api/compras/auditorias/{id}/items ───────────────────────
    public function itemsShow(int $id): JsonResponse
    {
        $auditoria = AuditoriaReceta::findOrFail($id);

        $items = AuditoriaItem::where('auditoria_id', $auditoria->id)
            ->with('criterio')
            ->get()
            ->map(fn ($i) => [
                'criterio_id'  => $i->criterio_id,
                'categoria'    => $i->criterio?->categoria,
                'nombre'       => $i->criterio?->nombre,
                'resultado'    => $i->resultado,
                'observaciones'=> $i->observaciones,
                'foto_url'     => $i->foto_url ? $this->presignS3Url($i->foto_url) : null,
            ]);

        return response()->json(['data' => $items]);
    }

    // ── POST /api/compras/auditorias/{id}/items ──────────────────────
    public function itemsSave(Request $request, int $id): JsonResponse
    {
        $auditoria = AuditoriaReceta::findOrFail($id);
        $submit    = $request->boolean('submit', false);

        // ── Auditoría multi-receta (nuevo formato, solo operaciones) ──────────────
        // Las recetas individuales se guardan vía /recetas/{itemId}/items.
        // Este endpoint solo se usa para: notas generales, fotos generales y submit final.
        // Las auditorías de calidad tienen receta_id=null pero usan el formato antiguo de criterios directos.
        if ($auditoria->receta_id === null && $auditoria->tipo !== 'calidad') {
            $validated = $request->validate([
                'items'                   => 'nullable|array',
                'observaciones_generales' => 'nullable|string|max:5000',
                'acciones_correctivas'    => 'nullable|string|max:5000',
                'secciones_fotos'         => 'nullable|array',
                'submit'                  => 'nullable|boolean',
            ]);

            DB::connection('compras')->transaction(function () use ($auditoria, $validated, $submit) {
                // Fotos generales de la visita (descripcion = null)
                if (!empty($validated['secciones_fotos'])) {
                    $seccionNames = array_map(fn ($s) => 'sec:' . $s, array_keys($validated['secciones_fotos']));
                    AuditoriaFoto::where('auditoria_id', $auditoria->id)
                        ->whereNull('receta_item_id')
                        ->whereIn('descripcion', $seccionNames)
                        ->delete();

                    foreach ($validated['secciones_fotos'] as $seccion => $urls) {
                        foreach ($urls as $idx => $url) {
                            AuditoriaFoto::create([
                                'auditoria_id' => $auditoria->id,
                                'url'          => $url,
                                'descripcion'  => 'sec:' . $seccion,
                                'orden'        => $idx,
                            ]);
                        }
                    }
                }

                $updates = [
                    'observaciones_generales' => $validated['observaciones_generales'] ?? $auditoria->observaciones_generales,
                    'acciones_correctivas'    => $validated['acciones_correctivas']    ?? $auditoria->acciones_correctivas,
                ];

                if ($submit) {
                    // Score global = promedio de todas las recetas evaluadas
                    $promedio = DB::connection('compras')
                        ->table('auditoria_receta_items')
                        ->where('auditoria_id', $auditoria->id)
                        ->whereNotNull('calificacion')
                        ->avg('calificacion');

                    $calificacion  = $promedio !== null ? round((float) $promedio, 1) : null;
                    $clasificacion = $this->calcularClasificacion($calificacion, $auditoria->tipo);

                    $nuevoEstado = match(true) {
                        in_array($auditoria->estado, ['respondida', 'cerrada']) => $auditoria->estado,
                        $auditoria->tipo === 'calidad' && $submit               => 'pendiente_respuesta',
                        $auditoria->tipo === 'calidad'                          => 'borrador',
                        $submit                                                  => 'pendiente_respuesta',
                        default                                                  => 'borrador',
                    };

                    $updates['calificacion']  = $calificacion;
                    $updates['clasificacion'] = $clasificacion;
                    $updates['estado']        = $nuevoEstado;

                    if ($submit && !$auditoria->submitted_at) {
                        $updates['submitted_at'] = now();
                        if ($auditoria->tipo === 'calidad') {
                            $updates['gerente_deadline_at'] = now()->addHours(48);
                        }
                    }
                }

                $auditoria->update($updates);
            });

            return response()->json(['message' => $submit ? 'Auditoría finalizada.' : 'Guardado.']);
        }

        // ── Auditoría formato antiguo (receta única) ──────────────────
        $validated = $request->validate([
            'items'                       => 'required|array',
            'items.*.criterio_id'         => 'required|integer|exists:compras.auditoria_criterios,id',
            'items.*.resultado'           => 'nullable|in:cumple,no_cumple,na',
            'items.*.observaciones'       => 'nullable|string|max:500',
            'items.*.foto_url'            => 'nullable|string|max:1000',
            'observaciones_generales'     => 'nullable|string|max:5000',
            'acciones_correctivas'        => 'nullable|string|max:5000',
            'secciones_fotos'             => 'nullable|array',
            'secciones_fotos.*'           => 'array',
            'secciones_fotos.*.*'         => 'string|max:1000',
            'submit'                      => 'nullable|boolean',
        ]);

        DB::connection('compras')->transaction(function () use ($auditoria, $validated, $submit) {
            // updateOrCreate falla con NULL porque genera WHERE receta_item_id = NULL (sin IS NULL).
            // Usamos upsert nativo de PostgreSQL (INSERT ON CONFLICT DO UPDATE).
            $now = now();
            $rows = array_map(fn ($item) => [
                'auditoria_id'   => $auditoria->id,
                'criterio_id'    => $item['criterio_id'],
                'receta_item_id' => null,
                'resultado'      => $item['resultado'] ?? null,
                'observaciones'  => $item['observaciones'] ?? null,
                'foto_url'       => $item['foto_url'] ?? null,
                'created_at'     => $now,
                'updated_at'     => $now,
            ], $validated['items']);

            DB::connection('compras')->table('auditoria_items')->upsert(
                $rows,
                ['auditoria_id', 'criterio_id', 'receta_item_id'], // índice único NULLS NOT DISTINCT
                ['resultado', 'observaciones', 'foto_url', 'updated_at']
            );

            // Fotos por sección: descripcion = 'sec:NombreSeccion'
            if (!empty($validated['secciones_fotos'])) {
                $seccionNames = array_map(fn ($s) => 'sec:' . $s, array_keys($validated['secciones_fotos']));
                AuditoriaFoto::where('auditoria_id', $auditoria->id)
                    ->whereNull('receta_item_id')
                    ->whereIn('descripcion', $seccionNames)
                    ->delete();

                foreach ($validated['secciones_fotos'] as $seccion => $urls) {
                    foreach ($urls as $idx => $url) {
                        AuditoriaFoto::create([
                            'auditoria_id' => $auditoria->id,
                            'url'          => $url,
                            'descripcion'  => 'sec:' . $seccion,
                            'orden'        => $idx,
                        ]);
                    }
                }
            }

            // Calcular calificación: cumplePeso / (totalMax - naPeso) × 100
            $criterioIds = collect($validated['items'])->pluck('criterio_id')->filter()->all();
            $pesos = AuditoriaCriterio::whereIn('id', $criterioIds)
                ->get(['id', 'peso'])
                ->keyBy('id');

            $totalMax = $naPeso = $pesoObtenido = $evaluados = 0;

            foreach ($validated['items'] as $item) {
                $resultado = $item['resultado'] ?? null;
                $peso = $pesos[$item['criterio_id']]?->peso ?? 1;
                $totalMax += $peso;
                if ($resultado === 'na')     { $naPeso += $peso; continue; }
                if (!$resultado)             continue;
                if ($resultado === 'cumple') $pesoObtenido += $peso;
                $evaluados++;
            }

            $denominador  = $totalMax - $naPeso;
            $calificacion = ($evaluados > 0 && $denominador > 0)
                ? round(($pesoObtenido / $denominador) * 100, 1)
                : null;
            $clasificacion = $this->calcularClasificacion($calificacion, $auditoria->tipo);

            // Estado según tipo: calidad usa borrador/pendiente_respuesta; operaciones usa evaluada
            // Si la auditoría ya está en respondida/cerrada, preservar ese estado (edición por Kristian)
            $nuevoEstado = match(true) {
                in_array($auditoria->estado, ['respondida', 'cerrada']) => $auditoria->estado,
                $auditoria->tipo === 'calidad' && $submit               => 'pendiente_respuesta',
                $auditoria->tipo === 'calidad'                          => 'borrador',
                $submit                                                  => 'evaluada',
                default                                                  => 'borrador',
            };

            $extra = [];
            if ($submit && $auditoria->tipo === 'calidad' && !$auditoria->submitted_at) {
                $extra['submitted_at']      = now();
                $extra['gerente_deadline_at'] = now()->addHours(48);
            }

            $auditoria->update(array_merge([
                'estado'                  => $nuevoEstado,
                'calificacion'            => $calificacion,
                'clasificacion'           => $clasificacion,
                'observaciones_generales' => $validated['observaciones_generales'] ?? null,
                'acciones_correctivas'    => $validated['acciones_correctivas'] ?? null,
            ], $extra));
        });

        // Notificar por correo al finalizar auditoría de calidad
        if ($submit && $auditoria->tipo === 'calidad' && $auditoria->sucursal_id) {
            try {
                $sucursalNombre = DB::connection('pgsql')
                    ->table('sucursales')
                    ->where('id', $auditoria->sucursal_id)
                    ->value('nombre') ?? 'Sucursal';

                // 1. Por cargo: gerente/jefe de cocina/chef ejecutivo activos en esa sucursal.
                $porCargo = DB::connection('pgsql')
                    ->table('empleados as e')
                    ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
                    ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
                    ->where('e.sucursal_id', $auditoria->sucursal_id)
                    ->where('e.activo', true)
                    ->whereRaw("LOWER(c.nombre) LIKE '%gerente%' OR LOWER(c.nombre) LIKE '%jefe de cocina%' OR LOWER(c.nombre) LIKE '%chef ejecutivo%'")
                    ->selectRaw('COALESCE(NULLIF(u.email, \'\'), e.email) AS email_destino')
                    ->pluck('email_destino');

                // 2. Por rol: usuarios con gerente_sucursal vinculados a esa sucursal
                //    (via users.sucursal_id o via empleados.sucursal_id como fallback).
                $porRol = DB::connection('pgsql')
                    ->table('users as u')
                    ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
                    ->join('roles as r', 'r.id', '=', 'ru.role_id')
                    ->leftJoin('empleados as e', 'e.user_id', '=', 'u.id')
                    ->where('r.codigo', 'gerente_sucursal')
                    ->where('u.activo', true)
                    ->where(function ($q) use ($auditoria) {
                        $q->where('u.sucursal_id', $auditoria->sucursal_id)
                          ->orWhere('e.sucursal_id', $auditoria->sucursal_id);
                    })
                    ->selectRaw('NULLIF(TRIM(u.email), \'\') AS email_destino')
                    ->pluck('email_destino');

                $destinatarios = $porCargo->merge($porRol)
                    ->filter(fn ($e) => $e && filter_var($e, FILTER_VALIDATE_EMAIL))
                    ->unique()
                    ->values()
                    ->toArray();

                if (!empty($destinatarios)) {
                    $linkUrl = config('app.frontend_compras_url', 'https://gestion-operaciones.cervezacadejo.com') . '/auditorias-calidad';
                    $deadlineLabel = $auditoria->gerente_deadline_at
                        ? $auditoria->gerente_deadline_at->setTimezone('America/El_Salvador')->format('d/m/Y H:i') . ' (hora SV)'
                        : null;
                    $mailable = new AuditoriaCalidadNotificacion(
                        sucursalNombre: $sucursalNombre,
                        fecha:          $auditoria->fecha?->format('d/m/Y') ?? '',
                        evaluadorNombre: $auditoria->evaluador_nombre ?? '',
                        calificacion:   $auditoria->calificacion !== null ? (float) $auditoria->calificacion : null,
                        clasificacion:  $auditoria->clasificacion,
                        observaciones:  $auditoria->observaciones_generales,
                        linkUrl:        $linkUrl,
                        deadlineAt:     $deadlineLabel,
                    );
                    Mail::to($destinatarios)->send($mailable);

                    // Registrar envío exitoso en email_logs
                    DB::connection('pgsql')->table('email_logs')->insert([
                        'sistema'         => 'compras',
                        'tipo'            => 'auditoria_calidad',
                        'destinatario'    => implode(', ', $destinatarios),
                        'asunto'          => $mailable->envelope()->subject,
                        'estado'          => 'enviado',
                        'enviado_por'     => request()->user()?->email ?? 'sistema',
                        'referencia_id'   => $auditoria->id,
                        'referencia_tipo' => 'auditoria',
                        'created_at'      => now(),
                    ]);
                } else {
                    // Sin destinatarios — registrar para trazabilidad
                    DB::connection('pgsql')->table('email_logs')->insert([
                        'sistema'         => 'compras',
                        'tipo'            => 'auditoria_calidad',
                        'destinatario'    => '',
                        'asunto'          => "Auditoría calidad #{$auditoria->id} — {$sucursalNombre}",
                        'estado'          => 'sin_destinatarios',
                        'enviado_por'     => request()->user()?->email ?? 'sistema',
                        'referencia_id'   => $auditoria->id,
                        'referencia_tipo' => 'auditoria',
                        'created_at'      => now(),
                    ]);
                }
            } catch (\Throwable $e) {
                // El correo es secundario — no rompemos la respuesta, pero sí registramos el error
                Log::error('AuditoriaCalidad: fallo al enviar notificación por correo', [
                    'auditoria_id' => $auditoria->id,
                    'sucursal_id'  => $auditoria->sucursal_id,
                    'error'        => $e->getMessage(),
                    'file'         => $e->getFile(),
                    'line'         => $e->getLine(),
                ]);
                try {
                    DB::connection('pgsql')->table('email_logs')->insert([
                        'sistema'         => 'compras',
                        'tipo'            => 'auditoria_calidad',
                        'destinatario'    => implode(', ', $destinatarios ?? []),
                        'asunto'          => "Auditoría calidad #{$auditoria->id} — " . ($sucursalNombre ?? ''),
                        'estado'          => 'fallido',
                        'enviado_por'     => request()->user()?->email ?? 'sistema',
                        'referencia_id'   => $auditoria->id,
                        'referencia_tipo' => 'auditoria',
                        'error_mensaje'   => $e->getMessage(),
                        'created_at'      => now(),
                    ]);
                } catch (\Throwable) {}
            }
        }

        return response()->json(['message' => 'Auditoría finalizada.']);
    }

    // ── POST /api/compras/auditorias/{id}/pdf ───────────────────────
    // Body JSON: { fotos_b64: { "Nombre Sección": ["data:image/jpeg;base64,..."] } }
    // El browser convierte las fotos de sección a base64 antes de enviar
    // (App Runner no puede descargar desde S3 directamente).
    public function pdf(Request $request, int $id): Response|JsonResponse
    {
        $auditoria = AuditoriaReceta::with(['estacion', 'receta', 'fotos'])->findOrFail($id);

        $sucursalNombre = DB::connection('pgsql')
            ->table('sucursales')
            ->where('id', $auditoria->sucursal_id)
            ->value('nombre') ?? 'Cadejo Brewing Company';

        $data = $this->formatAuditoria($auditoria, collect([$auditoria->sucursal_id => $sucursalNombre]));

        // Cargar criterios agrupados por categoría
        $tipo = $auditoria->tipo ?? 'operaciones';
        $criterios = AuditoriaCriterio::where('activo', true)
            ->where('tipo', $tipo)
            ->orderBy('categoria_orden')
            ->orderBy('orden')
            ->get(['id', 'categoria', 'nombre', 'peso']);

        // Cargar items evaluados
        $itemsMap = AuditoriaItem::where('auditoria_id', $auditoria->id)
            ->get()
            ->keyBy('criterio_id');

        // Fotos base64 recibidas del frontend: { "Seccion": ["data:image/...", ...] }
        $fotosB64 = $request->input('fotos_b64', []);

        // Construir secciones con criterios + resultados + fotos
        $secciones = $criterios->groupBy('categoria')->map(function ($items, $categoria) use ($itemsMap, $fotosB64) {
            return [
                'categoria' => $categoria,
                'fotos'     => $fotosB64[$categoria] ?? [],
                'items'     => $items->map(function ($c) use ($itemsMap) {
                    $item = $itemsMap->get($c->id);
                    return [
                        'criterio_id'  => $c->id,
                        'nombre'       => $c->nombre,
                        'resultado'    => $item?->resultado,
                        'observaciones'=> $item?->observaciones,
                    ];
                })->values()->toArray(),
            ];
        })->values()->toArray();

        try {
            $pdf = Pdf::loadView('pdf.auditoria', [
                'auditoria' => $data,
                'secciones' => $secciones,
            ])->setPaper('letter', 'portrait');

            $tipo_label = $tipo === 'calidad' ? 'calidad' : 'operaciones';
            $sucursal_slug = preg_replace('/[^A-Za-z0-9_\-]/', '_', $sucursalNombre);
            $nombre = "auditoria_{$tipo_label}_{$sucursal_slug}_{$auditoria->fecha?->format('Y-m-d')}.pdf";

            return $pdf->download($nombre);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'trace'   => collect(explode("\n", $e->getTraceAsString()))->take(10)->implode("\n"),
            ], 500);
        }
    }

    // ── GET /api/compras/auditorias/catalogos ────────────────────────
    public function catalogos(Request $request): JsonResponse
    {
        try {
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;

        // Estaciones
        $estacionesQ = Estacion::where('activa', true)->orderBy('nombre');
        if ($sucursalId) $estacionesQ->where('sucursal_id', $sucursalId);
        $estaciones = $estacionesQ->get(['id', 'codigo', 'nombre', 'sucursal_id']);

        // Centro de Producción (sucursal_id=21): sus empleados están en sucursal_id=14
        // (Casa Matriz) con departamento_id=16 (CP-Restaurantes).
        // Se mapea su sucursal_id a 21 para que el filtro del frontend funcione.
        $cocineros = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c', 'c.id', '=', 'e.cargo_id')
            ->where('e.activo', true)
            ->when($sucursalId, function ($q) use ($sucursalId) {
                if ($sucursalId === 21) {
                    $q->where('e.departamento_id', 16);
                } else {
                    $q->where('e.sucursal_id', $sucursalId);
                }
            })
            ->orderBy('e.nombres')
            ->select(
                'e.id',
                DB::raw("CONCAT(e.nombres, ' ', e.apellidos) as nombre_completo"),
                'c.nombre as cargo',
                DB::raw("CASE WHEN e.departamento_id = 16 THEN 21 ELSE e.sucursal_id END as sucursal_id")
            )
            ->get();

        // Sucursales operativas + producción (solo para auditorías de calidad)
        $sucursales = DB::connection('pgsql')
            ->table('sucursales as s')
            ->join('tipos_sucursal as ts', 'ts.id', '=', 's.tipo_sucursal_id')
            ->where('s.activa', true)
            ->whereIn('ts.codigo', ['operativa', 'produccion'])
            ->orderBy('s.nombre')
            ->select('s.id', 's.nombre')
            ->get();

        return response()->json([
            'estaciones' => $estaciones,
            'cocineros'  => $cocineros,
            'sucursales' => $sucursales,
        ]);
        } catch (\Throwable $e) {
            Log::error('AuditoriaCalidad: error en catalogos()', [
                'error' => $e->getMessage(),
                'file'  => $e->getFile(),
                'line'  => $e->getLine(),
            ]);
            return response()->json(['message' => 'Error al cargar catálogos. Intente de nuevo.'], 500);
        }
    }

    // ── POST /api/compras/auditorias/{id}/responder ──────────────────
    public function responder(Request $request, int $id): JsonResponse
    {
        $auditoria = AuditoriaReceta::findOrFail($id);

        if (!in_array($auditoria->tipo, ['calidad', 'operaciones'])) {
            return response()->json(['message' => 'Tipo de auditoría no soportado.'], 422);
        }

        if ($auditoria->estado !== 'pendiente_respuesta') {
            return response()->json(['message' => 'Esta auditoría ya no acepta respuestas.'], 422);
        }

        if ($auditoria->tipo === 'calidad' && $auditoria->gerente_deadline_at && now()->gt($auditoria->gerente_deadline_at)) {
            return response()->json(['message' => 'El plazo de 48 horas para apelar ha vencido.'], 422);
        }

        $validated = $request->validate([
            'comentario_gerente' => 'required|string|max:10000',
        ]);

        $user = $request->user();

        $updates = [
            'comentario_gerente'    => $validated['comentario_gerente'],
            'respondido_por_id'     => $user?->id,
            'respondido_por_nombre' => $user?->name,
            'respondido_at'         => now(),
            'estado'                => 'respondida',
            'gerente_respondio'     => true,
        ];
        if ($auditoria->tipo === 'calidad') {
            $updates['kristian_notificado_at'] = now();
            $updates['kristian_deadline_at']   = now()->addHours(48);
        }
        $auditoria->update($updates);

        if ($auditoria->tipo === 'calidad') {
            $sucursalNombre = DB::connection('pgsql')
                ->table('sucursales')->where('id', $auditoria->sucursal_id)->value('nombre') ?? 'Sucursal';
            $this->notificarKristian($auditoria, $sucursalNombre, apeló: true);
        }

        $sucursales = DB::connection('pgsql')->table('sucursales')
            ->where('id', $auditoria->sucursal_id)->pluck('nombre', 'id');

        return response()->json(['message' => 'Respuesta registrada.', 'data' => $this->formatAuditoria($auditoria, $sucursales)]);
    }

    // ── POST /api/compras/auditorias/{id}/reabrir ───────────────────
    public function reabrir(Request $request, int $id): JsonResponse
    {
        $auditoria = AuditoriaReceta::findOrFail($id);

        if ($auditoria->estado !== 'pendiente_respuesta') {
            return response()->json(['message' => 'Solo se pueden reabrir auditorías en estado "pendiente_respuesta".'], 422);
        }

        $auditoria->update([
            'estado'       => 'borrador',
            'submitted_at' => null,
        ]);

        $sucursales = DB::connection('pgsql')->table('sucursales')
            ->where('id', $auditoria->sucursal_id)->pluck('nombre', 'id');

        return response()->json(['message' => 'Auditoría reabierta.', 'data' => $this->formatAuditoria($auditoria, $sucursales)]);
    }

    // ── POST /api/compras/auditorias/{id}/cerrar ────────────────────
    public function cerrar(Request $request, int $id): JsonResponse
    {
        $auditoria = AuditoriaReceta::findOrFail($id);

        if ($auditoria->tipo !== 'calidad') {
            return response()->json(['message' => 'Solo aplica para auditorías de calidad.'], 422);
        }

        $estadosPermitidos = ['respondida'];
        // Sin deadline configurado (registro antiguo) ó con deadline vencido → puede cerrar
        $puedesCerrarPendiente = !$auditoria->gerente_deadline_at
            || now()->gt($auditoria->gerente_deadline_at);
        if ($auditoria->estado === 'pendiente_respuesta' && $puedesCerrarPendiente) {
            $estadosPermitidos[] = 'pendiente_respuesta';
        }

        if (!in_array($auditoria->estado, $estadosPermitidos)) {
            return response()->json(['message' => 'Solo se pueden cerrar auditorías en estado "respondida" (o "pendiente_respuesta" con plazo vencido).'], 422);
        }

        $validated = $request->validate([
            'nota_cierre' => 'nullable|string|max:2000',
        ]);

        $updates = [
            'estado'     => 'cerrada',
            'aud_usuario' => $request->user()?->name ?? $auditoria->aud_usuario,
        ];

        if (!empty($validated['nota_cierre'])) {
            $updates['observaciones_generales'] = $validated['nota_cierre'];
        }

        $auditoria->update($updates);

        $sucursales = DB::connection('pgsql')->table('sucursales')
            ->where('id', $auditoria->sucursal_id)->pluck('nombre', 'id');

        return response()->json(['message' => 'Auditoría cerrada.', 'data' => $this->formatAuditoria($auditoria, $sucursales)]);
    }

    // ── POST /api/compras/auditorias/{id}/reabrir ───────────────────
    // ── POST /api/compras/auditorias/{id}/recetas ────────────────────
    public function recetasStore(Request $request, int $id): JsonResponse
    {
        $auditoria = AuditoriaReceta::findOrFail($id);

        if (!$auditoria->es_multi_receta) {
            return response()->json(['message' => 'Esta auditoría no es de tipo multi-receta.'], 422);
        }

        $validated = $request->validate([
            'receta_id'          => 'required|integer|exists:compras.recetas,id',
            'tipo_receta'        => 'nullable|in:plato,sub_receta',
            'estacion_id'        => 'nullable|integer|exists:compras.estaciones,id',
            'responsable_id'     => 'nullable|integer',
            'responsable_nombre' => 'nullable|string|max:200',
        ]);

        $recetaNombre = DB::connection('compras')->table('recetas')
            ->where('id', $validated['receta_id'])
            ->value('nombre') ?? 'Receta';

        $estacionNombre = null;
        if (!empty($validated['estacion_id'])) {
            $estacionNombre = DB::connection('compras')->table('estaciones')
                ->where('id', $validated['estacion_id'])
                ->value('nombre');
        }

        $item = AuditoriaRecetaItem::create([
            'auditoria_id'       => $auditoria->id,
            'receta_id'          => $validated['receta_id'],
            'tipo_receta'        => $validated['tipo_receta'] ?? 'plato',
            'estacion_id'        => $validated['estacion_id'] ?? null,
            'responsable_id'     => $validated['responsable_id'] ?? null,
            'responsable_nombre' => $validated['responsable_nombre'] ?? null,
            'receta_nombre'      => $recetaNombre,
        ]);

        return response()->json(['data' => $this->formatRecetaItem($item)], 201);
    }

    // ── PUT /api/compras/auditorias/{id}/recetas/{itemId} ────────────
    public function recetasUpdate(Request $request, int $id, int $itemId): JsonResponse
    {
        $item = AuditoriaRecetaItem::where('auditoria_id', $id)->findOrFail($itemId);

        $validated = $request->validate([
            'estacion_id'        => 'nullable|integer|exists:compras.estaciones,id',
            'responsable_id'     => 'nullable|integer',
            'responsable_nombre' => 'nullable|string|max:200',
        ]);

        $estacionNombre = null;
        if (!empty($validated['estacion_id'])) {
            $estacionNombre = DB::connection('compras')->table('estaciones')
                ->where('id', $validated['estacion_id'])
                ->value('nombre');
        }

        $item->update([
            'estacion_id'        => $validated['estacion_id'] ?? null,
            'responsable_id'     => $validated['responsable_id'] ?? null,
            'responsable_nombre' => $validated['responsable_nombre'] ?? null,
        ]);

        return response()->json(['data' => $this->formatRecetaItem($item)]);
    }

    // ── DELETE /api/compras/auditorias/{id}/recetas/{itemId} ─────────
    public function recetasDestroy(int $id, int $itemId): JsonResponse
    {
        $item = AuditoriaRecetaItem::where('auditoria_id', $id)->findOrFail($itemId);

        // Eliminar fotos de sección de esta receta item
        AuditoriaFoto::where('auditoria_id', $id)
            ->where('descripcion', 'like', "ri:{$itemId}:%")
            ->delete();

        $item->delete(); // criterios se eliminan en cascada

        return response()->json(['message' => 'Receta eliminada de la auditoría.']);
    }

    // ── GET /api/compras/auditorias/{id}/recetas/{itemId}/items ──────
    public function recetaItemsShow(int $id, int $itemId): JsonResponse
    {
        AuditoriaReceta::findOrFail($id);
        AuditoriaRecetaItem::where('auditoria_id', $id)->findOrFail($itemId);

        $items = AuditoriaItem::where('auditoria_id', $id)
            ->where('receta_item_id', $itemId)
            ->with('criterio')
            ->get()
            ->map(fn ($i) => [
                'criterio_id'   => $i->criterio_id,
                'categoria'     => $i->criterio?->categoria,
                'nombre'        => $i->criterio?->nombre,
                'resultado'     => $i->resultado,
                'observaciones' => $i->observaciones,
                'foto_url'      => $i->foto_url ? $this->presignS3Url($i->foto_url) : null,
            ]);

        return response()->json(['data' => $items]);
    }

    // ── POST /api/compras/auditorias/{id}/recetas/{itemId}/items ─────
    public function recetaItemsSave(Request $request, int $id, int $itemId): JsonResponse
    {
        $auditoria  = AuditoriaReceta::findOrFail($id);
        $recetaItem = AuditoriaRecetaItem::where('auditoria_id', $id)->findOrFail($itemId);

        $validated = $request->validate([
            'items'                 => 'required|array',
            'items.*.criterio_id'   => 'required|integer|exists:compras.auditoria_criterios,id',
            'items.*.resultado'     => 'nullable|in:cumple,no_cumple,na',
            'items.*.observaciones' => 'nullable|string|max:500',
            'items.*.foto_url'      => 'nullable|string|max:1000',
            'secciones_fotos'       => 'nullable|array',
            'secciones_fotos.*'     => 'array',
            'secciones_fotos.*.*'   => 'string|max:1000',
        ]);

        DB::connection('compras')->transaction(function () use ($auditoria, $recetaItem, $validated, $id, $itemId) {
            foreach ($validated['items'] as $item) {
                AuditoriaItem::updateOrCreate(
                    ['auditoria_id' => $id, 'criterio_id' => $item['criterio_id'], 'receta_item_id' => $itemId],
                    [
                        'resultado'     => $item['resultado'] ?? null,
                        'observaciones' => $item['observaciones'] ?? null,
                        'foto_url'      => $item['foto_url'] ?? null,
                    ]
                );
            }

            if (!empty($validated['secciones_fotos'])) {
                $prefix = "ri:{$itemId}:sec:";
                $seccionNames = array_map(fn ($s) => $prefix . $s, array_keys($validated['secciones_fotos']));
                AuditoriaFoto::where('auditoria_id', $id)
                    ->where('receta_item_id', $itemId)
                    ->whereIn('descripcion', $seccionNames)
                    ->delete();

                foreach ($validated['secciones_fotos'] as $seccion => $urls) {
                    foreach ($urls as $idx => $url) {
                        AuditoriaFoto::create([
                            'auditoria_id'   => $id,
                            'receta_item_id' => $itemId,
                            'url'            => $url,
                            'descripcion'    => $prefix . $seccion,
                            'orden'          => $idx,
                        ]);
                    }
                }
            }

            $criterioIds = collect($validated['items'])->pluck('criterio_id')->filter()->all();
            $pesos = AuditoriaCriterio::whereIn('id', $criterioIds)->get(['id', 'peso'])->keyBy('id');

            $totalMax = $naPeso = $pesoObtenido = $evaluados = 0;
            foreach ($validated['items'] as $item) {
                $resultado = $item['resultado'] ?? null;
                $peso = $pesos[$item['criterio_id']]?->peso ?? 1;
                $totalMax += $peso;
                if ($resultado === 'na')     { $naPeso += $peso; continue; }
                if (!$resultado)             continue;
                if ($resultado === 'cumple') $pesoObtenido += $peso;
                $evaluados++;
            }

            $denominador  = $totalMax - $naPeso;
            $calificacion = ($evaluados > 0 && $denominador > 0)
                ? round(($pesoObtenido / $denominador) * 100, 1)
                : null;
            $clasificacion = $this->calcularClasificacion($calificacion, $auditoria->tipo);

            $recetaItem->update(['calificacion' => $calificacion, 'clasificacion' => $clasificacion]);
            $this->recalcularScoreGlobal($id);
        });

        $recetaItem->refresh();
        return response()->json(['calificacion' => $recetaItem->calificacion, 'clasificacion' => $recetaItem->clasificacion]);
    }

    // ── Helpers ──────────────────────────────────────────────────────
    private function formatAuditoria(AuditoriaReceta $a, $sucursales): array
    {
        $recetaItems = $a->relationLoaded('recetaItems')
            ? $a->recetaItems->map(fn ($ri) => $this->formatRecetaItem($ri))->values()->all()
            : null;

        return [
            'id'                      => $a->id,
            'tipo'                    => $a->tipo ?? 'operaciones',
            'fecha'                   => $a->fecha?->format('Y-m-d'),
            'hora'                    => substr($a->hora ?? '', 0, 5),
            'sucursal_id'             => $a->sucursal_id,
            'sucursal'                => $sucursales[$a->sucursal_id] ?? null,
            'estacion_id'             => $a->estacion_id,
            'estacion'                => $a->estacion?->nombre,
            'es_multi_receta'         => (bool) $a->es_multi_receta,
            'receta_items'            => $recetaItems ?? [],
            'receta_items_count'      => $recetaItems !== null ? count($recetaItems) : ($a->recetaItems()->count()),
            'receta_id'               => $a->receta_id,
            'receta'                  => $a->receta?->nombre,
            'tipo_receta'             => $a->tipo_receta,
            'responsable_id'          => $a->responsable_id,
            'responsable_nombre'      => $a->responsable_nombre,
            'evaluador_id'            => $a->evaluador_id,
            'evaluador_nombre'        => $a->evaluador_nombre,
            'notas'                   => $a->notas,
            'estado'                  => $a->estado,
            'calificacion'            => $a->calificacion !== null ? (float) $a->calificacion : null,
            'clasificacion'           => $a->clasificacion,
            'observaciones_generales'  => $a->observaciones_generales,
            'acciones_correctivas'     => $a->acciones_correctivas,
            'comentario_gerente'       => $a->comentario_gerente,
            'respondido_por_id'        => $a->respondido_por_id,
            'respondido_por_nombre'    => $a->respondido_por_nombre,
            'respondido_at'            => $a->respondido_at?->toIso8601String(),
            // Flujo apelación / deadlines
            'submitted_at'             => $a->submitted_at?->toIso8601String(),
            'gerente_deadline_at'      => $a->gerente_deadline_at?->toIso8601String(),
            'gerente_respondio'        => $a->gerente_respondio,
            'kristian_notificado_at'   => $a->kristian_notificado_at?->toIso8601String(),
            'kristian_deadline_at'     => $a->kristian_deadline_at?->toIso8601String(),
            'fotos'                    => $a->relationLoaded('fotos')
                ? $a->fotos->map(fn ($f) => [
                    'id'          => $f->id,
                    'url'         => $this->presignS3Url($f->url),
                    'descripcion' => $f->descripcion,
                  ])
                : [],
            'receta_items'             => $a->relationLoaded('recetaItems')
                ? $a->recetaItems->map(fn ($ri) => $this->formatRecetaItem($ri))->values()
                : [],
            'receta_items_count'       => $a->relationLoaded('recetaItems')
                ? $a->recetaItems->count()
                : ($a->receta_items_count ?? null),
            'es_multi_receta'          => $a->receta_id === null,
            'created_at'               => $a->created_at?->toIso8601String(),
        ];
    }

    private function formatRecetaItem(AuditoriaRecetaItem $ri): array
    {
        return [
            'id'                 => $ri->id,
            'receta_id'          => $ri->receta_id,
            'receta_nombre'      => $ri->receta_nombre ?? $ri->receta?->nombre,
            'tipo_receta'        => $ri->tipo_receta,
            'estacion_id'        => $ri->estacion_id,
            'estacion_nombre'    => $ri->estacion?->nombre,
            'responsable_id'     => $ri->responsable_id,
            'responsable_nombre' => $ri->responsable_nombre,
            'calificacion'       => $ri->calificacion !== null ? (float) $ri->calificacion : null,
            'clasificacion'      => $ri->clasificacion,
            'notas'              => $ri->notas,
            'orden'              => $ri->orden,
        ];
    }

    private function calcularClasificacion(?float $calificacion, ?string $tipo): ?string
    {
        if ($calificacion === null) return null;
        if ($tipo === 'operaciones') {
            if ($calificacion >= 98) return 'Excelente';
            if ($calificacion >= 90) return 'Muy Bueno';
            if ($calificacion >= 80) return 'Bueno';
            if ($calificacion >= 70) return 'Aceptable';
            return 'Deficiente';
        }
        if ($calificacion >= 90) return 'Excelente';
        if ($calificacion >= 75) return 'Bueno';
        if ($calificacion >= 60) return 'Aceptable';
        return 'Deficiente';
    }

    private function recalcularScoreGlobal(int $auditoriaId): void
    {
        $promedio = DB::connection('compras')
            ->table('auditoria_receta_items')
            ->where('auditoria_id', $auditoriaId)
            ->whereNotNull('calificacion')
            ->avg('calificacion');

        $auditoria     = AuditoriaReceta::find($auditoriaId);
        $calificacion  = $promedio !== null ? round((float) $promedio, 1) : null;
        $clasificacion = $this->calcularClasificacion($calificacion, $auditoria?->tipo);

        AuditoriaReceta::where('id', $auditoriaId)->update([
            'calificacion'  => $calificacion,
            'clasificacion' => $clasificacion,
        ]);
    }

    private function notificarKristian(AuditoriaReceta $auditoria, string $sucursalNombre, bool $apeló): void
    {
        try {
            $linkUrl = config('app.frontend_compras_url', 'https://gestion-operaciones.cervezacadejo.com') . '/auditorias-calidad';
            Mail::to('kristian@cervezacadejo.com')->send(
                new \App\Mail\Compras\KristianAuditoriaNotificacion(
                    sucursalNombre:   $sucursalNombre,
                    fecha:            $auditoria->fecha?->format('d/m/Y') ?? '',
                    evaluadorNombre:  $auditoria->evaluador_nombre ?? '',
                    calificacion:     $auditoria->calificacion !== null ? (float) $auditoria->calificacion : null,
                    clasificacion:    $auditoria->clasificacion,
                    comentarioGerente: $auditoria->comentario_gerente,
                    apelo:            $apeló,
                    linkUrl:          $linkUrl,
                )
            );

            DB::connection('pgsql')->table('email_logs')->insert([
                'sistema'         => 'compras',
                'tipo'            => 'auditoria_kristian',
                'destinatario'    => 'kristian@cervezacadejo.com',
                'asunto'          => ($apeló ? 'Apelación' : 'Auditoría sin apelar') . " — {$sucursalNombre}",
                'estado'          => 'enviado',
                'enviado_por'     => 'sistema',
                'referencia_id'   => $auditoria->id,
                'referencia_tipo' => 'auditoria',
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            Log::error('AuditoriaCalidad: fallo al notificar a Kristian', [
                'auditoria_id' => $auditoria->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }

    /**
     * Convierte una URL de S3 a una presigned GET URL válida por 2 horas.
     * Generación local (HMAC-SHA256), sin llamadas de red.
     */
    private function presignS3Url(?string $url): ?string
    {
        if (!$url) return null;

        $bucket = config('filesystems.disks.s3.bucket');
        $region = config('filesystems.disks.s3.region');
        $prefix = "https://{$bucket}.s3.{$region}.amazonaws.com/";

        // Quitar query params en caso de que ya sea una URL presignada guardada por error
        $cleanUrl = strtok($url, '?');

        if (!str_starts_with($cleanUrl, $prefix)) return $url;

        $key = substr($cleanUrl, strlen($prefix));

        try {
            static $s3Client = null;
            if (!$s3Client) {
                $cfg = ['region' => $region, 'version' => 'latest'];
                $awsKey    = config('filesystems.disks.s3.key');
                $awsSecret = config('filesystems.disks.s3.secret');
                // Solo pasar credenciales explícitas si están configuradas.
                // En App Runner/EC2 sin credenciales env, el SDK usa el rol IAM de la instancia.
                if (!empty($awsKey) && !empty($awsSecret)) {
                    $cfg['credentials'] = ['key' => $awsKey, 'secret' => $awsSecret];
                }
                $s3Client = new \Aws\S3\S3Client($cfg);
            }
            $cmd = $s3Client->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
            return (string) $s3Client->createPresignedRequest($cmd, '+2 hours')->getUri();
        } catch (\Throwable $e) {
            Log::warning('presignS3Url (auditorias): fallo al generar URL pre-firmada', [
                'url'   => $url,
                'error' => $e->getMessage(),
            ]);
            return $url;
        }
    }
}
