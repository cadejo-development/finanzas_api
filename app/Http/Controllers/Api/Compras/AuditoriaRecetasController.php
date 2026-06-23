<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaFoto;
use App\Models\AuditoriaItem;
use App\Models\AuditoriaCriterio;
use App\Models\AuditoriaReceta;
use App\Models\Estacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
            'receta_id'          => 'required|integer|exists:compras.recetas,id',
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
        $estadoInicial = $tipoAuditoria === 'calidad' ? 'borrador' : 'completada';

        $auditoria = DB::connection('compras')->transaction(function () use ($validated, $usuario, $user, $tipoAuditoria, $estadoInicial): AuditoriaReceta {
            $auditoria = AuditoriaReceta::create([
                'tipo'               => $tipoAuditoria,
                'fecha'              => $validated['fecha'],
                'hora'               => $validated['hora'],
                'sucursal_id'        => $validated['sucursal_id'],
                'estacion_id'        => $validated['estacion_id'] ?? null,
                'receta_id'          => $validated['receta_id'],
                'tipo_receta'        => $validated['tipo_receta'] ?? 'plato',
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
        $auditoria  = AuditoriaReceta::with(['estacion', 'receta', 'fotos'])->findOrFail($id);
        $sucursales = DB::connection('pgsql')->table('sucursales')
            ->where('id', $auditoria->sucursal_id)->pluck('nombre', 'id');

        return response()->json(['data' => $this->formatAuditoria($auditoria, $sucursales)]);
    }

    // ── DELETE /api/compras/auditorias/{id} ──────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $auditoria = AuditoriaReceta::findOrFail($id);
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

            $todasSucs = DB::connection('pgsql')->table('sucursales')
                ->orderBy('nombre')
                ->get(['id', 'nombre']);

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

        return response()->json([
            'resumen' => [
                'total'       => $total,
                'con_fotos'   => $conFotos,
                'evaluadores' => $evaluadores,
                'desde'       => $desde,
                'hasta'       => $hasta,
            ],
            'por_sucursal'      => $porSucursalFmt,
            'por_estado'        => $porEstado,
            'por_clasificacion' => $porClasificacion,
            'top_recetas'       => $topRecetasFmt,
            'tendencia'         => $tendencia,
            'por_evaluador'     => $porEvaluador,
            'ultimas'           => $ultimasFmt,
            'alertas_pendientes' => $alertasPendientes,
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

        $validated = $request->validate([
            'items'                       => 'required|array',
            'items.*.criterio_id'         => 'required|integer|exists:compras.auditoria_criterios,id',
            'items.*.resultado'           => 'nullable|in:cumple,no_cumple,na',
            'items.*.observaciones'       => 'nullable|string|max:500',
            'items.*.foto_url'            => 'nullable|string|max:1000',
            'observaciones_generales'     => 'nullable|string|max:2000',
            'acciones_correctivas'        => 'nullable|string|max:2000',
            'secciones_fotos'             => 'nullable|array',
            'secciones_fotos.*'           => 'array',
            'secciones_fotos.*.*'         => 'string|max:1000',
            'submit'                      => 'nullable|boolean',
        ]);
        $submit = $request->boolean('submit', false);

        DB::connection('compras')->transaction(function () use ($auditoria, $validated) {
            foreach ($validated['items'] as $item) {
                AuditoriaItem::updateOrCreate(
                    ['auditoria_id' => $auditoria->id, 'criterio_id' => $item['criterio_id']],
                    [
                        'resultado'     => $item['resultado'] ?? null,
                        'observaciones' => $item['observaciones'] ?? null,
                        'foto_url'      => $item['foto_url'] ?? null,
                    ]
                );
            }

            // Fotos por sección: descripcion = 'sec:NombreSeccion'
            if (!empty($validated['secciones_fotos'])) {
                $seccionNames = array_map(fn ($s) => 'sec:' . $s, array_keys($validated['secciones_fotos']));
                AuditoriaFoto::where('auditoria_id', $auditoria->id)
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

            // Calcular calificación: cumple / (cumple + no_cumple) × 100 (N/A y sin evaluar no cuentan)
            $criterioIds = collect($validated['items'])->pluck('criterio_id')->filter()->all();
            $pesos = AuditoriaCriterio::whereIn('id', $criterioIds)
                ->get(['id', 'peso'])
                ->keyBy('id');

            $totalPeso    = 0;
            $pesoObtenido = 0;
            $evaluados    = 0;

            foreach ($validated['items'] as $item) {
                $resultado = $item['resultado'] ?? null;
                if (!$resultado || $resultado === 'na') continue;
                $peso = $pesos[$item['criterio_id']]?->peso ?? 1;
                $totalPeso += $peso;
                if ($resultado === 'cumple') {
                    $pesoObtenido += $peso;
                }
                $evaluados++;
            }

            $calificacion = $evaluados > 0
                ? round(($pesoObtenido / $totalPeso) * 100, 1)
                : null;

            // Clasificación según umbrales del formato oficial
            $clasificacion = null;
            if ($calificacion !== null) {
                if ($calificacion >= 90)      $clasificacion = 'Excelente';
                elseif ($calificacion >= 75)  $clasificacion = 'Bueno';
                elseif ($calificacion >= 60)  $clasificacion = 'Aceptable';
                else                          $clasificacion = 'Deficiente';
            }

            // Estado según tipo: calidad usa borrador/pendiente_respuesta; operaciones usa evaluada
            $nuevoEstado = match(true) {
                $auditoria->tipo === 'calidad' && $submit => 'pendiente_respuesta',
                $auditoria->tipo === 'calidad'             => 'borrador',
                default                                    => 'evaluada',
            };

            $auditoria->update([
                'estado'                  => $nuevoEstado,
                'calificacion'            => $calificacion,
                'clasificacion'           => $clasificacion,
                'observaciones_generales' => $validated['observaciones_generales'] ?? null,
                'acciones_correctivas'    => $validated['acciones_correctivas'] ?? null,
            ]);
        });

        return response()->json(['message' => 'Evaluación guardada.']);
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

        // Responsables: todos los empleados activos de la sucursal
        $cocineros = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c', 'c.id', '=', 'e.cargo_id')
            ->where('e.activo', true)
            ->when($sucursalId, fn ($q) => $q->where('e.sucursal_id', $sucursalId))
            ->orderBy('e.nombres')
            ->select('e.id', DB::raw("CONCAT(e.nombres, ' ', e.apellidos) as nombre_completo"), 'c.nombre as cargo', 'e.sucursal_id')
            ->get();

        // Sucursales operativas activas (excluir área corporativa)
        $sucursales = DB::connection('pgsql')
            ->table('sucursales as s')
            ->join('tipos_sucursal as ts', 'ts.id', '=', 's.tipo_sucursal_id')
            ->where('s.activa', true)
            ->where('ts.codigo', 'operativa')
            ->orderBy('s.nombre')
            ->select('s.id', 's.nombre')
            ->get();

        return response()->json([
            'estaciones' => $estaciones,
            'cocineros'  => $cocineros,
            'sucursales' => $sucursales,
        ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'file'    => $e->getFile(),
                'line'    => $e->getLine(),
            ], 500);
        }
    }

    // ── POST /api/compras/auditorias/{id}/responder ──────────────────
    public function responder(Request $request, int $id): JsonResponse
    {
        $auditoria = AuditoriaReceta::findOrFail($id);

        if ($auditoria->tipo !== 'calidad') {
            return response()->json(['message' => 'Solo aplica para auditorías de calidad.'], 422);
        }

        $validated = $request->validate([
            'acciones_correctivas' => 'required|string|max:2000',
        ]);

        $user = $request->user();

        $auditoria->update([
            'acciones_correctivas'  => $validated['acciones_correctivas'],
            'respondido_por_id'     => $user?->id,
            'respondido_por_nombre' => $user?->name,
            'respondido_at'         => now(),
            'estado'                => 'respondida',
        ]);

        $sucursales = DB::connection('pgsql')->table('sucursales')
            ->where('id', $auditoria->sucursal_id)->pluck('nombre', 'id');

        return response()->json(['message' => 'Respuesta registrada.', 'data' => $this->formatAuditoria($auditoria, $sucursales)]);
    }

    // ── Helpers ──────────────────────────────────────────────────────
    private function formatAuditoria(AuditoriaReceta $a, $sucursales): array
    {
        return [
            'id'                      => $a->id,
            'tipo'                    => $a->tipo ?? 'operaciones',
            'fecha'                   => $a->fecha?->format('Y-m-d'),
            'hora'                    => substr($a->hora ?? '', 0, 5),
            'sucursal_id'             => $a->sucursal_id,
            'sucursal'                => $sucursales[$a->sucursal_id] ?? null,
            'estacion_id'             => $a->estacion_id,
            'estacion'                => $a->estacion?->nombre,
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
            'respondido_por_id'        => $a->respondido_por_id,
            'respondido_por_nombre'    => $a->respondido_por_nombre,
            'respondido_at'            => $a->respondido_at?->toIso8601String(),
            'fotos'                    => $a->relationLoaded('fotos')
                ? $a->fotos->map(fn ($f) => [
                    'id'          => $f->id,
                    'url'         => $this->presignS3Url($f->url),
                    'descripcion' => $f->descripcion,
                  ])
                : [],
            'created_at'               => $a->created_at?->toIso8601String(),
        ];
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

        if (!str_starts_with($url, $prefix)) return $url;

        $key = substr($url, strlen($prefix));

        try {
            static $s3Client = null;
            if (!$s3Client) {
                $s3Client = new \Aws\S3\S3Client([
                    'region'      => $region,
                    'version'     => 'latest',
                    'credentials' => [
                        'key'    => config('filesystems.disks.s3.key'),
                        'secret' => config('filesystems.disks.s3.secret'),
                    ],
                ]);
            }
            $cmd = $s3Client->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
            return (string) $s3Client->createPresignedRequest($cmd, '+2 hours')->getUri();
        } catch (\Throwable) {
            return $url;
        }
    }
}
