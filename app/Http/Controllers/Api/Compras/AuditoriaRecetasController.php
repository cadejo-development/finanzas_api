<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\AuditoriaFoto;
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

        $query = AuditoriaReceta::with(['estacion', 'receta', 'fotos'])
            ->orderByDesc('fecha')
            ->orderByDesc('hora');

        if ($sucursalId)  $query->where('sucursal_id', $sucursalId);
        if ($desde)       $query->whereDate('fecha', '>=', $desde);
        if ($hasta)       $query->whereDate('fecha', '<=', $hasta);
        if ($recetaId)    $query->where('receta_id', $recetaId);
        if ($evaluadorId) $query->where('evaluador_id', $evaluadorId);

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

        $auditoria = DB::connection('compras')->transaction(function () use ($validated, $usuario, $user): AuditoriaReceta {
            $auditoria = AuditoriaReceta::create([
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
                'estado'             => 'completada',
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

        $base = DB::connection('compras')->table('auditorias_receta')
            ->whereDate('fecha', '>=', $desde)
            ->whereDate('fecha', '<=', $hasta);

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
            ->select('sucursal_id', DB::raw('COUNT(*) as total'))
            ->groupBy('sucursal_id')
            ->orderByDesc('total')
            ->get();

        $sucursalIds2 = $porSucursal->pluck('sucursal_id')->unique()->filter()->toArray();
        $sucursalesMap = $sucursalIds2
            ? DB::connection('pgsql')->table('sucursales')->whereIn('id', $sucursalIds2)->pluck('nombre', 'id')
            : collect();

        $porSucursalFmt = $porSucursal->map(fn ($r) => [
            'sucursal_id'  => $r->sucursal_id,
            'sucursal'     => $sucursalesMap[$r->sucursal_id] ?? "Sucursal {$r->sucursal_id}",
            'total'        => (int) $r->total,
        ]);

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
            'fecha'              => $a->fecha?->format('Y-m-d'),
            'hora'               => substr($a->hora ?? '', 0, 5),
            'sucursal'           => $sucUltimas[$a->sucursal_id] ?? '',
            'estacion'           => $a->estacion?->nombre,
            'receta'             => $a->receta?->nombre,
            'evaluador_nombre'   => $a->evaluador_nombre,
            'responsable_nombre' => $a->responsable_nombre,
        ]);

        return response()->json([
            'resumen' => [
                'total'       => $total,
                'con_fotos'   => $conFotos,
                'evaluadores' => $evaluadores,
                'desde'       => $desde,
                'hasta'       => $hasta,
            ],
            'por_sucursal'  => $porSucursalFmt,
            'top_recetas'   => $topRecetasFmt,
            'tendencia'     => $tendencia,
            'por_evaluador' => $porEvaluador,
            'ultimas'       => $ultimasFmt,
        ]);
    }

    // ── GET /api/compras/auditorias/catalogos ────────────────────────
    // Devuelve estaciones y cocineros filtrados por sucursal
    public function catalogos(Request $request): JsonResponse
    {
        try {
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;

        // Estaciones
        $estacionesQ = Estacion::where('activa', true)->orderBy('nombre');
        if ($sucursalId) $estacionesQ->where('sucursal_id', $sucursalId);
        $estaciones = $estacionesQ->get(['id', 'codigo', 'nombre', 'sucursal_id']);

        // Cocineros (cargos de cocina) desde pgsql core_db
        $cargosCocinaCodigos = [18, 19, 20, 45, 60, 62, 63]; // Chef, Cocinero/a, Jefe Cocina, Sous Chef...
        $cocineros = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'c.id', '=', 'e.cargo_id')
            ->where('e.activo', true)
            ->whereIn('e.cargo_id', $cargosCocinaCodigos)
            ->when($sucursalId, fn ($q) => $q->where('e.sucursal_id', $sucursalId))
            ->orderBy('e.nombre')
            ->select('e.id', DB::raw("CONCAT(e.nombre, ' ', e.apellidos) as nombre_completo"), 'c.nombre as cargo', 'e.sucursal_id')
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

    // ── Helpers ──────────────────────────────────────────────────────
    private function formatAuditoria(AuditoriaReceta $a, $sucursales): array
    {
        return [
            'id'                 => $a->id,
            'fecha'              => $a->fecha?->format('Y-m-d'),
            'hora'               => substr($a->hora ?? '', 0, 5),
            'sucursal_id'        => $a->sucursal_id,
            'sucursal'           => $sucursales[$a->sucursal_id] ?? null,
            'estacion_id'        => $a->estacion_id,
            'estacion'           => $a->estacion?->nombre,
            'receta_id'          => $a->receta_id,
            'receta'             => $a->receta?->nombre,
            'tipo_receta'        => $a->tipo_receta,
            'responsable_id'     => $a->responsable_id,
            'responsable_nombre' => $a->responsable_nombre,
            'evaluador_id'       => $a->evaluador_id,
            'evaluador_nombre'   => $a->evaluador_nombre,
            'notas'              => $a->notas,
            'estado'             => $a->estado,
            'fotos'              => $a->relationLoaded('fotos')
                ? $a->fotos->map(fn ($f) => ['id' => $f->id, 'url' => $f->url, 'descripcion' => $f->descripcion])
                : [],
            'created_at'         => $a->created_at?->toIso8601String(),
        ];
    }
}
