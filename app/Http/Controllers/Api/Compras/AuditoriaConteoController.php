<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Mail\Compras\AuditoriaConteoNotificacion;
use App\Mail\Compras\RespuestaAuditoriaConteoNotificacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class AuditoriaConteoController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/auditoria-conteo?sucursal_id=X&fecha_conteo=Y
    // Devuelve la auditoría activa o cerrada para una fecha/sucursal
    // ─────────────────────────────────────────────────────────────────────────
    public function getAuditoria(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id'  => 'required|integer',
            'fecha_conteo' => 'required|date',
        ]);

        $auditoria = DB::connection('compras')
            ->table('conteo_auditorias')
            ->where('sucursal_id', $request->sucursal_id)
            ->where('fecha_conteo', $request->fecha_conteo)
            ->first();

        if (!$auditoria) {
            return response()->json(['success' => true, 'data' => null]);
        }

        $items = DB::connection('compras')
            ->table('conteo_auditoria_items')
            ->where('auditoria_id', $auditoria->id)
            ->orderBy('producto_nombre')
            ->get();

        $respuesta = DB::connection('compras')
            ->table('conteo_auditoria_respuestas')
            ->where('auditoria_id', $auditoria->id)
            ->first();

        return response()->json([
            'success' => true,
            'data'    => [
                'auditoria' => $auditoria,
                'items'     => $items,
                'respuesta' => $respuesta,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/compras/inventario/auditoria-conteo/iniciar
    // Inicia una nueva auditoría para el conteo mensual de una fecha
    // ─────────────────────────────────────────────────────────────────────────
    public function iniciar(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id'  => 'required|integer',
            'fecha_conteo' => 'required|date',
        ]);

        $authUser = Auth::user();
        $esAdmin  = $authUser->hasRole('admin_compras') || $authUser->hasRole('gerencia_financiera') || $authUser->hasRole('dir_comercial');
        $esAuditor = $authUser->hasPermission('puede_auditar_conteo');

        if (!$esAdmin && !$esAuditor) {
            return response()->json(['success' => false, 'message' => 'No tienes permiso para realizar auditorías.'], 403);
        }

        $sucursalId = (int) $request->sucursal_id;
        $fecha      = $request->fecha_conteo;

        // Verificar que no exista ya una auditoría
        $existing = DB::connection('compras')
            ->table('conteo_auditorias')
            ->where('sucursal_id', $sucursalId)
            ->where('fecha_conteo', $fecha)
            ->first();

        if ($existing) {
            return response()->json(['success' => false, 'message' => 'Ya existe una auditoría para esta fecha.'], 422);
        }

        // Obtener los movimientos de conteo_fisico para esa fecha
        $movs = DB::connection('compras')
            ->table('movimientos_inventario as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->where('m.sucursal_id', $sucursalId)
            ->where('m.tipo', 'conteo_mensual')
            ->where('m.fecha', $fecha)
            ->select('m.producto_id', 'p.nombre as producto_nombre', 'm.detalle', 'p.unidad as p_unidad', 'm.unidad', 'm.created_at')
            ->orderBy('m.created_at')
            ->get();

        if ($movs->isEmpty()) {
            return response()->json(['success' => false, 'message' => 'No existe conteo aplicado para esta fecha.'], 422);
        }

        // Último movimiento por producto gana (igual que conteoHoy)
        $byProd = [];
        foreach ($movs as $mov) {
            $d = is_string($mov->detalle) ? json_decode($mov->detalle, true) : (array)($mov->detalle ?? []);
            $byProd[$mov->producto_id] = [
                'producto_id'       => $mov->producto_id,
                'producto_nombre'   => $mov->producto_nombre,
                'cantidad_contador' => $d['total_contado'] ?? 0,
                'unidad'            => $mov->unidad ?? $mov->p_unidad,
            ];
        }

        // Obtener nombre de la sucursal
        $sucursalNombre = DB::table('sucursales')
            ->where('id', $sucursalId)
            ->value('nombre') ?? 'Sucursal';

        // Crear la auditoría
        $auditoriaId = DB::connection('compras')->table('conteo_auditorias')->insertGetId([
            'sucursal_id'         => $sucursalId,
            'fecha_conteo'        => $fecha,
            'estado'              => 'activa',
            'auditado_por'        => $authUser->id,
            'auditado_por_nombre' => $authUser->nombre ?? $authUser->email,
            'created_at'          => now(),
            'updated_at'          => now(),
        ]);

        // Crear los items
        $now = now();
        $items = array_values(array_map(fn($p) => [
            'auditoria_id'      => $auditoriaId,
            'producto_id'       => $p['producto_id'],
            'producto_nombre'   => $p['producto_nombre'],
            'cantidad_contador' => $p['cantidad_contador'],
            'unidad'            => $p['unidad'],
            'comprobado'        => false,
            'created_at'        => $now,
            'updated_at'        => $now,
        ], $byProd));

        DB::connection('compras')->table('conteo_auditoria_items')->insert($items);

        return $this->_buildResponse($auditoriaId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/compras/inventario/auditoria-conteo/{id}/item/{productoId}
    // Marca un producto como comprobado y opcionalmente edita la cantidad
    // ─────────────────────────────────────────────────────────────────────────
    public function actualizarItem(Request $request, int $auditoriaId, int $productoId): JsonResponse
    {
        $request->validate([
            'comprobado'      => 'required|boolean',
            'cantidad_auditor' => 'nullable|numeric|min:0',
            'observacion'      => 'nullable|string|max:500',
        ]);

        $rows = DB::connection('compras')
            ->table('conteo_auditoria_items')
            ->where('auditoria_id', $auditoriaId)
            ->where('producto_id', $productoId)
            ->update([
                'comprobado'       => $request->comprobado,
                'comprobado_por'   => $request->comprobado ? Auth::user()->email : null,
                'cantidad_auditor' => $request->comprobado ? $request->cantidad_auditor : null,
                'observacion'      => $request->observacion,
                'updated_at'       => now(),
            ]);

        if (!$rows) {
            return response()->json(['success' => false, 'message' => 'Item no encontrado.'], 404);
        }

        return response()->json(['success' => true, 'message' => 'Item actualizado.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/compras/inventario/auditoria-conteo/{id}/cerrar
    // Cierra la auditoría con firma y envía notificación por email
    // ─────────────────────────────────────────────────────────────────────────
    public function cerrar(Request $request, int $auditoriaId): JsonResponse
    {
        $request->validate(['firma' => 'required|string|max:1000']);

        $auditoria = DB::connection('compras')
            ->table('conteo_auditorias')
            ->where('id', $auditoriaId)
            ->first();

        if (!$auditoria) {
            return response()->json(['success' => false, 'message' => 'Auditoría no encontrada.'], 404);
        }

        DB::connection('compras')->table('conteo_auditorias')
            ->where('id', $auditoriaId)
            ->update([
                'estado'          => 'cerrada',
                'firma_auditoria' => $request->firma,
                'cerrado_at'      => now(),
                'updated_at'      => now(),
            ]);

        // Notificar al gerente/contador por correo
        $sucursalNombre = DB::table('sucursales')
            ->where('id', $auditoria->sucursal_id)
            ->value('nombre') ?? 'Sucursal';

        $auditorNombre = $auditoria->auditado_por_nombre ?? Auth::user()?->nombre ?? Auth::user()?->email ?? 'Auditor';

        $comprobados = DB::connection('compras')
            ->table('conteo_auditoria_items')
            ->where('auditoria_id', $auditoriaId)
            ->where('comprobado', true)
            ->count();

        try {
            Mail::to('javiermejia@cervezacadejo.com')
                ->send(new AuditoriaConteoNotificacion(
                    sucursalNombre: $sucursalNombre,
                    fecha: $auditoria->fecha_conteo,
                    auditorNombre: $auditorNombre,
                    totalComprobados: $comprobados,
                    firma: $request->firma,
                ));
        } catch (\Throwable) {
            // El email no bloquea el cierre
        }

        return $this->_buildResponse($auditoriaId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/compras/inventario/auditoria-conteo/{id}/responder
    // Gerente/contador responde la auditoría (aprobada | rechazada)
    // ─────────────────────────────────────────────────────────────────────────
    public function responder(Request $request, int $auditoriaId): JsonResponse
    {
        $request->validate([
            'decision'    => 'required|in:aprobada,rechazada',
            'comentarios' => 'nullable|string|max:1000',
        ]);

        if ($request->decision === 'rechazada' && empty(trim($request->comentarios ?? ''))) {
            return response()->json(['success' => false, 'message' => 'Los comentarios son obligatorios al rechazar.'], 422);
        }

        $auditoria = DB::connection('compras')
            ->table('conteo_auditorias')
            ->where('id', $auditoriaId)
            ->first();

        if (!$auditoria) {
            return response()->json(['success' => false, 'message' => 'Auditoría no encontrada.'], 404);
        }

        $authUser    = Auth::user();
        $respondente = $authUser->nombre ?? $authUser->email;
        $now         = now();

        // Upsert: si ya existe respuesta la reemplaza
        $existing = DB::connection('compras')
            ->table('conteo_auditoria_respuestas')
            ->where('auditoria_id', $auditoriaId)
            ->first();

        if ($existing) {
            DB::connection('compras')->table('conteo_auditoria_respuestas')
                ->where('id', $existing->id)
                ->update([
                    'decision'              => $request->decision,
                    'comentarios'           => $request->comentarios,
                    'respondido_por'        => $authUser->id,
                    'respondido_por_nombre' => $respondente,
                    'updated_at'            => $now,
                ]);
        } else {
            DB::connection('compras')->table('conteo_auditoria_respuestas')->insert([
                'auditoria_id'          => $auditoriaId,
                'decision'              => $request->decision,
                'comentarios'           => $request->comentarios,
                'respondido_por'        => $authUser->id,
                'respondido_por_nombre' => $respondente,
                'created_at'            => $now,
                'updated_at'            => $now,
            ]);
        }

        // Notificar al auditor
        $sucursalNombre = DB::table('sucursales')
            ->where('id', $auditoria->sucursal_id)
            ->value('nombre') ?? 'Sucursal';

        try {
            Mail::to('javiermejia@cervezacadejo.com')
                ->send(new RespuestaAuditoriaConteoNotificacion(
                    sucursalNombre: $sucursalNombre,
                    fecha: $auditoria->fecha_conteo,
                    respondente: $respondente,
                    decision: $request->decision,
                    comentarios: $request->comentarios,
                ));
        } catch (\Throwable) {
            // El email no bloquea la operación
        }

        return $this->_buildResponse($auditoriaId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/compras/inventario/auditoria-conteo/{id}/reabrir
    // Regresa la auditoría cerrada a estado "activa"
    // ─────────────────────────────────────────────────────────────────────────
    public function reabrir(int $auditoriaId): JsonResponse
    {
        $auditoria = DB::connection('compras')
            ->table('conteo_auditorias')
            ->where('id', $auditoriaId)
            ->first();

        if (!$auditoria) {
            return response()->json(['success' => false, 'message' => 'Auditoría no encontrada.'], 404);
        }

        if ($auditoria->estado !== 'cerrada') {
            return response()->json(['success' => false, 'message' => 'Solo se pueden reabrir auditorías cerradas.'], 422);
        }

        DB::connection('compras')->table('conteo_auditorias')
            ->where('id', $auditoriaId)
            ->update([
                'estado'          => 'activa',
                'firma_auditoria' => null,
                'cerrado_at'      => null,
                'updated_at'      => now(),
            ]);

        return $this->_buildResponse($auditoriaId);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/auditoria-conteo/{id}/borrador
    // Devuelve el borrador de trabajo del auditor actual para esta auditoría
    // ─────────────────────────────────────────────────────────────────────────
    public function getBorrador(int $auditoriaId): JsonResponse
    {
        $row = DB::connection('compras')
            ->table('auditoria_borradores')
            ->where('auditoria_id', $auditoriaId)
            ->where('aud_usuario', Auth::user()->email)
            ->first();

        return response()->json([
            'success' => true,
            'data'    => $row ? [
                'payload'    => is_string($row->payload) ? json_decode($row->payload, true) : (array) $row->payload,
                'updated_at' => $row->updated_at,
            ] : null,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/compras/inventario/auditoria-conteo/{id}/borrador
    // Guarda/actualiza el borrador de trabajo del auditor actual
    // ─────────────────────────────────────────────────────────────────────────
    public function saveBorrador(Request $request, int $auditoriaId): JsonResponse
    {
        $request->validate(['payload' => 'required|array']);

        $now   = now();
        $email = Auth::user()->email;

        DB::connection('compras')
            ->table('auditoria_borradores')
            ->upsert(
                [[
                    'auditoria_id' => $auditoriaId,
                    'aud_usuario'  => $email,
                    'payload'      => json_encode($request->payload),
                    'created_at'   => $now,
                    'updated_at'   => $now,
                ]],
                ['auditoria_id', 'aud_usuario'],
                ['payload', 'updated_at']
            );

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/auditoria-conteo/{id}/estadisticas
    // Devuelve tabla comparativa (conteo vs Brilo) con Kardex y justificaciones
    // ─────────────────────────────────────────────────────────────────────────
    public function getEstadisticas(Request $request, int $auditoriaId): JsonResponse
    {
        $auditoria = DB::connection('compras')
            ->table('conteo_auditorias')
            ->where('id', $auditoriaId)
            ->first();

        if (!$auditoria) {
            return response()->json(['success' => false, 'message' => 'Auditoría no encontrada.'], 404);
        }

        $sucursalId = $auditoria->sucursal_id;
        $fecha      = $auditoria->fecha_conteo;

        // ── Productos con datos de auditoría (inventario + brilo_stock + costo) ──
        $itemsRaw = DB::connection('compras')
            ->table('conteo_auditoria_items as ai')
            ->join('productos as p', 'p.id', '=', 'ai.producto_id')
            ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
            ->leftJoin('inventarios as inv', fn($j) =>
                $j->on('inv.producto_id', '=', 'p.id')->where('inv.sucursal_id', $sucursalId))
            ->where('ai.auditoria_id', $auditoriaId)
            ->select(
                'ai.producto_id', 'ai.cantidad_contador', 'ai.cantidad_auditor',
                'ai.comprobado', 'ai.comprobado_por',
                'ai.justificacion', 'ai.justificacion_obs',
                'p.codigo', 'p.nombre', 'p.unidad', 'p.costo',
                'cat.nombre as categoria',
                'inv.brilo_stock'
            )
            ->get();

        // ── Kardex BRILO para el período ─────────────────────────────────────
        $kardexMeta = DB::connection('compras')
            ->table('brilo_kardex')
            ->where('sucursal_id', $sucursalId)
            ->where('fecha_hasta', $fecha)
            ->orderByDesc('synced_at')
            ->first();

        $kardex     = [];
        $fechaDesde = $kardexMeta?->fecha_desde ?? null;

        if ($kardexMeta) {
            $kRows = DB::connection('compras')
                ->table('brilo_kardex')
                ->where('sucursal_id', $sucursalId)
                ->where('fecha_desde', $kardexMeta->fecha_desde)
                ->where('fecha_hasta', $fecha)
                ->get();
            foreach ($kRows as $r) {
                $kardex[$r->producto_codigo] = [
                    'saldo_ini' => (float) $r->saldo_ini,
                    'entradas'  => (float) $r->entradas,
                    'salidas'   => (float) $r->salidas,
                    'saldo_fin' => (float) $r->saldo_fin,
                ];
            }
        }

        // ── Construir filas ───────────────────────────────────────────────────
        $filas = [];
        foreach ($itemsRaw as $ai) {
            $conteo  = round((float) ($ai->cantidad_auditor ?? $ai->cantidad_contador), 4);
            $brilo   = $ai->brilo_stock !== null ? round((float) $ai->brilo_stock, 4) : null;
            $diff    = $brilo !== null ? round($conteo - $brilo, 4) : null;
            $diffPct = ($brilo !== null && $brilo != 0) ? round($diff / $brilo * 100, 2) : null;
            $costo   = $ai->costo !== null ? round((float) $ai->costo, 4) : null;
            $costoDiff = ($diff !== null && $costo !== null) ? round($diff * $costo, 2) : null;

            if ($brilo === null)        $tipo = 'SIN_BRILO';
            elseif (abs($diff) <= 0.01) $tipo = 'OK';
            elseif ($diff < 0)          $tipo = 'FALTANTE';
            else                        $tipo = 'SOBRANTE';

            $kd = $kardex[$ai->codigo] ?? null;

            $filas[] = [
                'producto_id'      => (int) $ai->producto_id,
                'codigo'           => $ai->codigo,
                'nombre'           => $ai->nombre,
                'categoria'        => $ai->categoria ?? '—',
                'unidad'           => $ai->unidad,
                'conteo'           => $conteo,
                'brilo'            => $brilo,
                'diff'             => $diff,
                'diff_pct'         => $diffPct,
                'tipo'             => $tipo,
                'costo'            => $costo,
                'costo_diff'       => $costoDiff,
                'k_saldo_ini'      => $kd['saldo_ini'] ?? null,
                'k_entradas'       => $kd['entradas']  ?? null,
                'k_salidas'        => $kd['salidas']   ?? null,
                'k_saldo_fin'      => $kd['saldo_fin'] ?? null,
                'comprobado'       => (bool) $ai->comprobado,
                'comprobado_por'   => $ai->comprobado_por,
                'justificacion'    => $ai->justificacion,
                'justificacion_obs'=> $ai->justificacion_obs,
            ];
        }

        // Ordenar: faltante de mayor costo primero, luego sobrantes, ok, sin brilo
        usort($filas, fn($a, $b) => ($a['costo_diff'] ?? 0) <=> ($b['costo_diff'] ?? 0));

        // ── Filtros opcionales ────────────────────────────────────────────────
        $tipoFilter = $request->query('tipo');
        $search     = strtolower(trim($request->query('search', '')));

        if ($tipoFilter && $tipoFilter !== 'todos') {
            $filas = array_values(array_filter($filas, fn($f) => $f['tipo'] === strtoupper($tipoFilter)));
        }
        if ($search !== '') {
            $filas = array_values(array_filter($filas, fn($f) =>
                str_contains(strtolower($f['nombre']), $search) ||
                str_contains(strtolower($f['codigo']), $search)
            ));
        }

        // ── Paginación ────────────────────────────────────────────────────────
        $perPage = max(1, min(200, (int) $request->query('per_page', 25)));
        $page    = max(1, (int) $request->query('page', 1));
        $total   = count($filas);
        $items   = array_slice($filas, ($page - 1) * $perPage, $perPage);

        // ── Resumen ───────────────────────────────────────────────────────────
        $nFalt     = count(array_filter($filas, fn($f) => $f['tipo'] === 'FALTANTE'));
        $nSobr     = count(array_filter($filas, fn($f) => $f['tipo'] === 'SOBRANTE'));
        $nOk       = count(array_filter($filas, fn($f) => $f['tipo'] === 'OK'));
        $nSinBrilo = count(array_filter($filas, fn($f) => $f['tipo'] === 'SIN_BRILO'));
        $costoFalt = round(array_sum(array_map(
            fn($f) => $f['tipo'] === 'FALTANTE' && $f['costo_diff'] !== null ? abs($f['costo_diff']) : 0, $filas
        )), 2);
        $costoSobr = round(array_sum(array_map(
            fn($f) => $f['tipo'] === 'SOBRANTE' && $f['costo_diff'] !== null ? $f['costo_diff'] : 0, $filas
        )), 2);

        return response()->json([
            'success' => true,
            'data'    => [
                'items'      => $items,
                'pagination' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))],
                'resumen'    => compact('nFalt', 'nSobr', 'nOk', 'nSinBrilo', 'costoFalt', 'costoSobr'),
                'kardex_meta'=> $fechaDesde ? ['fecha_desde' => $fechaDesde, 'fecha_hasta' => $fecha] : null,
                'auditoria'  => ['id' => $auditoriaId, 'estado' => $auditoria->estado, 'fecha_conteo' => $fecha],
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/auditoria-conteo/estadisticas-directas
    // Estadísticas de conteo mensual sin necesitar auditoría (para admin)
    // ─────────────────────────────────────────────────────────────────────────
    public function getEstadisticasDirectas(Request $request): JsonResponse
    {
        $sucursalId = (int) $request->query('sucursal_id');
        $fecha      = $request->query('fecha');

        if (!$sucursalId || !$fecha) {
            return response()->json(['success' => false, 'message' => 'sucursal_id y fecha son requeridos.'], 422);
        }

        // Tomar un movimiento por producto (el real, no sin_contar)
        $movs = DB::connection('compras')
            ->table('movimientos_inventario as m')
            ->join('productos as p', 'p.id', '=', 'm.producto_id')
            ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
            ->leftJoin('inventarios as inv', fn($j) =>
                $j->on('inv.producto_id', '=', 'p.id')->where('inv.sucursal_id', $sucursalId))
            ->where('m.sucursal_id', $sucursalId)
            ->where('m.tipo', 'conteo_mensual')
            ->whereRaw("DATE(m.fecha) = ?", [$fecha])
            ->where('m.aud_usuario', '!=', 'sin_contar')
            ->select(
                'm.producto_id', 'm.cantidad', 'm.aud_usuario',
                'p.codigo', 'p.nombre', 'p.unidad', 'p.costo',
                'cat.nombre as categoria',
                'inv.brilo_stock'
            )
            ->orderBy('m.producto_id')
            ->orderByDesc('m.created_at')
            ->get()
            ->unique('producto_id');

        // ── Kardex BRILO para el período ─────────────────────────────────────
        $kardexMeta = DB::connection('compras')
            ->table('brilo_kardex')
            ->where('sucursal_id', $sucursalId)
            ->where('fecha_hasta', $fecha)
            ->orderByDesc('synced_at')
            ->first();

        $kardex     = [];
        $fechaDesde = $kardexMeta?->fecha_desde ?? null;

        if ($kardexMeta) {
            $kRows = DB::connection('compras')
                ->table('brilo_kardex')
                ->where('sucursal_id', $sucursalId)
                ->where('fecha_desde', $kardexMeta->fecha_desde)
                ->where('fecha_hasta', $fecha)
                ->get();
            foreach ($kRows as $r) {
                $kardex[$r->producto_codigo] = [
                    'saldo_ini' => (float) $r->saldo_ini,
                    'entradas'  => (float) $r->entradas,
                    'salidas'   => (float) $r->salidas,
                    'saldo_fin' => (float) $r->saldo_fin,
                ];
            }
        }

        // ── Construir filas ───────────────────────────────────────────────────
        $filas = [];
        foreach ($movs as $m) {
            $conteo  = round((float) $m->cantidad, 4);
            $brilo   = $m->brilo_stock !== null ? round((float) $m->brilo_stock, 4) : null;
            $diff    = $brilo !== null ? round($conteo - $brilo, 4) : null;
            $diffPct = ($brilo !== null && $brilo != 0) ? round($diff / $brilo * 100, 2) : null;
            $costo   = $m->costo !== null ? round((float) $m->costo, 4) : null;
            $costoDiff = ($diff !== null && $costo !== null) ? round($diff * $costo, 2) : null;

            if ($brilo === null)        $tipo = 'SIN_BRILO';
            elseif (abs($diff) <= 0.01) $tipo = 'OK';
            elseif ($diff < 0)          $tipo = 'FALTANTE';
            else                        $tipo = 'SOBRANTE';

            $kd = $kardex[$m->codigo] ?? null;

            $filas[] = [
                'producto_id'      => (int) $m->producto_id,
                'codigo'           => $m->codigo,
                'nombre'           => $m->nombre,
                'categoria'        => $m->categoria ?? '—',
                'unidad'           => $m->unidad,
                'conteo'           => $conteo,
                'brilo'            => $brilo,
                'diff'             => $diff,
                'diff_pct'         => $diffPct,
                'tipo'             => $tipo,
                'costo'            => $costo,
                'costo_diff'       => $costoDiff,
                'k_saldo_ini'      => $kd['saldo_ini'] ?? null,
                'k_entradas'       => $kd['entradas']  ?? null,
                'k_salidas'        => $kd['salidas']   ?? null,
                'k_saldo_fin'      => $kd['saldo_fin'] ?? null,
                'justificacion'    => null,
                'justificacion_obs'=> null,
            ];
        }

        usort($filas, fn($a, $b) => ($a['costo_diff'] ?? 0) <=> ($b['costo_diff'] ?? 0));

        // ── Filtros ───────────────────────────────────────────────────────────
        $tipoFilter = $request->query('tipo');
        $search     = strtolower(trim($request->query('search', '')));

        if ($tipoFilter && $tipoFilter !== 'todos') {
            $filas = array_values(array_filter($filas, fn($f) => $f['tipo'] === strtoupper($tipoFilter)));
        }
        if ($search !== '') {
            $filas = array_values(array_filter($filas, fn($f) =>
                str_contains(strtolower($f['nombre']), $search) ||
                str_contains(strtolower($f['codigo']), $search)
            ));
        }

        // ── Paginación ────────────────────────────────────────────────────────
        $perPage = max(1, min(200, (int) $request->query('per_page', 25)));
        $page    = max(1, (int) $request->query('page', 1));
        $total   = count($filas);
        $items   = array_slice($filas, ($page - 1) * $perPage, $perPage);

        $nFalt     = count(array_filter($filas, fn($f) => $f['tipo'] === 'FALTANTE'));
        $nSobr     = count(array_filter($filas, fn($f) => $f['tipo'] === 'SOBRANTE'));
        $nOk       = count(array_filter($filas, fn($f) => $f['tipo'] === 'OK'));
        $nSinBrilo = count(array_filter($filas, fn($f) => $f['tipo'] === 'SIN_BRILO'));
        $costoFalt = round(array_sum(array_map(
            fn($f) => $f['tipo'] === 'FALTANTE' && $f['costo_diff'] !== null ? abs($f['costo_diff']) : 0, $filas
        )), 2);
        $costoSobr = round(array_sum(array_map(
            fn($f) => $f['tipo'] === 'SOBRANTE' && $f['costo_diff'] !== null ? $f['costo_diff'] : 0, $filas
        )), 2);

        return response()->json([
            'success' => true,
            'data'    => [
                'items'      => $items,
                'pagination' => ['total' => $total, 'page' => $page, 'per_page' => $perPage, 'last_page' => max(1, (int) ceil($total / $perPage))],
                'resumen'    => compact('nFalt', 'nSobr', 'nOk', 'nSinBrilo', 'costoFalt', 'costoSobr'),
                'kardex_meta'=> $fechaDesde ? ['fecha_desde' => $fechaDesde, 'fecha_hasta' => $fecha] : null,
                'auditoria'  => null,
                'sin_auditoria' => true,
            ],
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // PUT /api/compras/inventario/auditoria-conteo/{id}/justificar/{productoId}
    // Guarda la justificación de diferencia para un producto
    // ─────────────────────────────────────────────────────────────────────────
    public function guardarJustificacion(Request $request, int $auditoriaId, int $productoId): JsonResponse
    {
        $request->validate([
            'justificacion'     => 'nullable|string|max:100',
            'justificacion_obs' => 'nullable|string|max:500',
        ]);

        $rows = DB::connection('compras')
            ->table('conteo_auditoria_items')
            ->where('auditoria_id', $auditoriaId)
            ->where('producto_id', $productoId)
            ->update([
                'justificacion'     => $request->justificacion,
                'justificacion_obs' => $request->justificacion_obs,
                'updated_at'        => now(),
            ]);

        if (!$rows) {
            return response()->json(['success' => false, 'message' => 'Producto no encontrado en esta auditoría.'], 404);
        }

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helper: devuelve el payload completo de una auditoría por ID
    // ─────────────────────────────────────────────────────────────────────────
    private function _buildResponse(int $auditoriaId): JsonResponse
    {
        $auditoria = DB::connection('compras')
            ->table('conteo_auditorias')
            ->where('id', $auditoriaId)
            ->first();

        $items = DB::connection('compras')
            ->table('conteo_auditoria_items')
            ->where('auditoria_id', $auditoriaId)
            ->orderBy('producto_nombre')
            ->get();

        $respuesta = DB::connection('compras')
            ->table('conteo_auditoria_respuestas')
            ->where('auditoria_id', $auditoriaId)
            ->first();

        return response()->json([
            'success' => true,
            'data'    => compact('auditoria', 'items', 'respuesta'),
        ]);
    }
}
