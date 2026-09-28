<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Mail\Compras\AuditoriaConteoNotificacion;
use App\Mail\Compras\RespuestaAuditoriaConteoNotificacion;
use App\Mail\JustificacionesInventarioMail;
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

        return $this->_buildResponse($auditoria->id);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/compras/inventario/auditoria-conteo/fechas?sucursal_id=X
    // Devuelve fechas (YYYY-MM-DD) que tienen auditoría registrada
    // ─────────────────────────────────────────────────────────────────────────
    public function fechas(Request $request): JsonResponse
    {
        $sucursalId = (int) $request->query('sucursal_id');
        if (!$sucursalId) {
            return response()->json(['data' => []]);
        }

        $fechas = DB::connection('compras')
            ->table('conteo_auditorias')
            ->where('sucursal_id', $sucursalId)
            ->selectRaw('fecha_conteo::text AS fecha')
            ->orderByDesc('fecha_conteo')
            ->pluck('fecha')
            ->all();

        return response()->json(['success' => true, 'data' => $fechas]);
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
        $esAuditor = $authUser->esAuditorInventario($request->input('sucursal_id'));

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
            $secsRaw = array_filter((array)($d['secciones'] ?? []), fn($v) => (float)$v > 0);
            $byProd[$mov->producto_id] = [
                'producto_id'       => $mov->producto_id,
                'producto_nombre'   => $mov->producto_nombre,
                'cantidad_contador' => $d['total_contado'] ?? 0,
                'unidad'            => $mov->unidad ?? $mov->p_unidad,
                'secciones_conteo'  => json_encode(empty($secsRaw) ? (object)[] : $secsRaw),
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
            'auditoria_id'          => $auditoriaId,
            'producto_id'           => $p['producto_id'],
            'producto_nombre'       => $p['producto_nombre'],
            'cantidad_contador'     => $p['cantidad_contador'],
            'unidad'                => $p['unidad'],
            'secciones_conteo'      => $p['secciones_conteo'],
            'secciones_comprobadas' => '{}',
            'comprobado'            => false,
            'created_at'            => $now,
            'updated_at'            => $now,
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
            'secciones_comprobadas' => 'nullable|array',
            'observacion'           => 'nullable|string|max:500',
            // backwards compat
            'comprobado'            => 'nullable|boolean',
            'cantidad_auditor'      => 'nullable|numeric|min:0',
        ]);

        $auditoria = DB::connection('compras')
            ->table('conteo_auditorias')
            ->where('id', $auditoriaId)
            ->first();

        if ($auditoria && in_array($auditoria->estado, ['cerrada', 'aprobada'])) {
            return response()->json(['success' => false, 'message' => 'Esta auditoría ya fue cerrada y no puede modificarse.'], 403);
        }

        $email = Auth::user()->email;
        $now   = now();

        // Obtener la fila base (auditor_email='') para datos del conteo
        $base = DB::connection('compras')
            ->table('conteo_auditoria_items')
            ->where('auditoria_id', $auditoriaId)
            ->where('producto_id', $productoId)
            ->where('auditor_email', '')
            ->first();

        if (!$base) {
            return response()->json(['success' => false, 'message' => 'Item no encontrado.'], 404);
        }

        if ($request->has('secciones_comprobadas')) {
            $seccionesComprobadas = $request->secciones_comprobadas ?? [];

            // cantidad_auditor = suma de las secciones que este auditor comprobó
            $cantidadAuditor = array_sum(array_map(
                fn($v) => (float)($v['cantidad'] ?? 0),
                array_values($seccionesComprobadas)
            ));

            // comprobado para ESTE auditor = tiene al menos una sección auditada (puede corregir sección)
            $comprobado = !empty($seccionesComprobadas);

            // Upsert: cada auditor tiene su propia fila (auditor_email distinto)
            DB::connection('compras')
                ->table('conteo_auditoria_items')
                ->upsert(
                    [[
                        'auditoria_id'          => $auditoriaId,
                        'producto_id'           => $productoId,
                        'auditor_email'         => $email,
                        'producto_nombre'       => $base->producto_nombre,
                        'cantidad_contador'     => $base->cantidad_contador,
                        'unidad'                => $base->unidad,
                        'secciones_conteo'      => $base->secciones_conteo ?? '{}',
                        'secciones_comprobadas' => json_encode(empty($seccionesComprobadas) ? (object)[] : $seccionesComprobadas),
                        'cantidad_auditor'      => $cantidadAuditor > 0 ? $cantidadAuditor : null,
                        'comprobado'            => $comprobado,
                        'comprobado_por'        => $comprobado ? $email : null,
                        'observacion'           => $request->observacion,
                        'created_at'            => $now,
                        'updated_at'            => $now,
                    ]],
                    ['auditoria_id', 'producto_id', 'auditor_email'],
                    ['secciones_comprobadas', 'cantidad_auditor', 'comprobado', 'comprobado_por', 'observacion', 'updated_at']
                );
        } else {
            // Flujo legacy (sin secciones / quitar completo): upsert fila del auditor
            DB::connection('compras')
                ->table('conteo_auditoria_items')
                ->upsert(
                    [[
                        'auditoria_id'          => $auditoriaId,
                        'producto_id'           => $productoId,
                        'auditor_email'         => $email,
                        'producto_nombre'       => $base->producto_nombre,
                        'cantidad_contador'     => $base->cantidad_contador,
                        'unidad'                => $base->unidad,
                        'secciones_conteo'      => $base->secciones_conteo ?? '{}',
                        'secciones_comprobadas' => $request->comprobado ? '{}' : '{}',
                        'comprobado'            => $request->comprobado ?? false,
                        'comprobado_por'        => $request->comprobado ? $email : null,
                        'cantidad_auditor'      => $request->comprobado ? $request->cantidad_auditor : null,
                        'observacion'           => $request->observacion,
                        'created_at'            => $now,
                        'updated_at'            => $now,
                    ]],
                    ['auditoria_id', 'producto_id', 'auditor_email'],
                    ['secciones_comprobadas', 'cantidad_auditor', 'comprobado', 'comprobado_por', 'observacion', 'updated_at']
                );
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

        // Contar productos (únicos) donde algún auditor los marcó comprobado
        $comprobados = DB::connection('compras')
            ->table('conteo_auditoria_items')
            ->where('auditoria_id', $auditoriaId)
            ->where('auditor_email', '!=', '')
            ->where('comprobado', true)
            ->distinct('producto_id')
            ->count('producto_id');

        // Jefes de departamento de la sucursal → email de login (@cervezacadejo.com)
        $gerentes = DB::table('departamentos as d')
            ->join('empleados as e', 'e.id', '=', 'd.jefe_empleado_id')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('d.sucursal_id', $auditoria->sucursal_id)
            ->where('d.activo', true)
            ->where('e.activo', true)
            ->where('u.activo', true)
            ->whereNotNull('u.email')
            ->select('u.email', DB::raw("e.nombres || ' ' || e.apellidos as nombre"))
            ->distinct()
            ->get();

        try {
            $destinatarios = $gerentes->isNotEmpty()
                ? $gerentes->map(fn($g) => ['email' => $g->email, 'name' => $g->nombre])->all()
                : [['email' => 'javiermejia@cervezacadejo.com', 'name' => 'Javier Mejia']];

            Mail::to($destinatarios)
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
        // Solo filas base (auditor_email='') para datos del conteo; cantidad_auditor consolidada desde filas de auditores
        $itemsRaw = DB::connection('compras')
            ->table('conteo_auditoria_items as ai')
            ->join('productos as p', 'p.id', '=', 'ai.producto_id')
            ->leftJoin('categorias as cat', 'cat.id', '=', 'p.categoria_id')
            ->leftJoin('inventarios as inv', fn($j) =>
                $j->on('inv.producto_id', '=', 'p.id')->where('inv.sucursal_id', $sucursalId))
            ->leftJoinSub(
                DB::connection('compras')->table('conteo_auditoria_items')
                    ->where('auditoria_id', $auditoriaId)
                    ->where('auditor_email', '!=', '')
                    ->selectRaw('producto_id, SUM(cantidad_auditor) as cant_aud_consolidada, MAX(comprobado::int)::boolean as comprobado_cons, MAX(comprobado_por) as comprobado_por_cons, MAX(justificacion) as just_cons, MAX(justificacion_obs) as just_obs_cons')
                    ->groupBy('producto_id'),
                'aud',
                'aud.producto_id', '=', 'ai.producto_id'
            )
            ->where('ai.auditoria_id', $auditoriaId)
            ->where('ai.auditor_email', '')
            ->where('p.excluir_estadisticas', false)
            ->select(
                'ai.producto_id', 'ai.cantidad_contador',
                DB::raw('aud.cant_aud_consolidada as cantidad_auditor'),
                DB::raw('COALESCE(aud.comprobado_cons, false) as comprobado'),
                'aud.comprobado_por_cons as comprobado_por',
                DB::raw('COALESCE(aud.just_cons, ai.justificacion) as justificacion'),
                DB::raw('COALESCE(aud.just_obs_cons, ai.justificacion_obs) as justificacion_obs'),
                'p.codigo', 'p.nombre', 'p.unidad', 'p.costo',
                'cat.nombre as categoria',
                'inv.brilo_stock'
            )
            ->get();

        // ── Kardex BRILO para el período ─────────────────────────────────────
        // Busca el kardex más reciente cuyo FIN PERÍODO caiga dentro de los 5 días
        // anteriores al conteo (cubre casos donde el conteo se registró un día
        // después de medianoche pero el FIN PERÍODO real es el día anterior).
        $kardexMeta = DB::connection('compras')
            ->table('brilo_kardex')
            ->where('sucursal_id', $sucursalId)
            ->where('fecha_hasta', '<=', $fecha)
            ->where('fecha_hasta', '>=', \Carbon\Carbon::parse($fecha)->subDays(5)->format('Y-m-d'))
            ->orderByDesc('fecha_hasta')
            ->orderByDesc('synced_at')
            ->first();

        $kardex     = [];
        $fechaDesde = $kardexMeta?->fecha_desde ?? null;

        if ($kardexMeta) {
            $kRows = DB::connection('compras')
                ->table('brilo_kardex')
                ->where('sucursal_id', $sucursalId)
                ->where('fecha_desde', $kardexMeta->fecha_desde)
                ->where('fecha_hasta', $kardexMeta->fecha_hasta)
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

        // ── Ajustes post-conteo (ajuste/merma aplicados después del conteo) ───
        $ajustesMap = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('sucursal_id', $sucursalId)
            ->whereIn('tipo', ['ajuste', 'merma'])
            ->whereRaw("DATE(fecha) >= ?", [$fecha])
            ->selectRaw('producto_id, SUM(cantidad_base) as total_ajuste')
            ->groupBy('producto_id')
            ->get()
            ->keyBy('producto_id');

        // ── Construir filas ───────────────────────────────────────────────────
        $filas = [];
        foreach ($itemsRaw as $ai) {
            $conteo  = round((float) ($ai->cantidad_auditor ?? $ai->cantidad_contador), 4);
            $kd      = $kardex[$ai->codigo] ?? null;
            $brilo   = $kd !== null ? round($kd['saldo_fin'], 4) : null;
            $ajuste  = isset($ajustesMap[$ai->producto_id])
                ? round((float) $ajustesMap[$ai->producto_id]->total_ajuste, 4)
                : 0.0;
            $conteoEfectivo = round($conteo + $ajuste, 4);
            $diff    = $brilo !== null ? round($conteoEfectivo - $brilo, 4) : null;
            $costo   = $ai->costo !== null ? round((float) $ai->costo, 4) : null;
            $costoDiff = ($diff !== null && $costo !== null) ? round($diff * $costo, 2) : null;

            if ($brilo === null)        $tipo = 'SIN_BRILO';
            elseif (abs($diff) <= 0.01) $tipo = 'OK';
            elseif ($diff < 0)          $tipo = 'FALTANTE';
            else                        $tipo = 'SOBRANTE';

            $kSalidas = $kd !== null ? $kd['salidas'] : null;
            $diffPct  = ($diff !== null && $kSalidas !== null && ($kSalidas + abs($diff)) > 0)
                ? round(abs($diff) / (abs($diff) + $kSalidas) * 100, 2)
                : null;

            $filas[] = [
                'producto_id'        => (int) $ai->producto_id,
                'codigo'             => $ai->codigo,
                'nombre'             => $ai->nombre,
                'categoria'          => $ai->categoria ?? '—',
                'unidad'             => $ai->unidad,
                'conteo'             => $conteo,
                'ajuste_post_conteo' => $ajuste !== 0.0 ? $ajuste : null,
                'brilo'              => $brilo,
                'diff'               => $diff,
                'diff_pct'           => $diffPct,
                'tipo'               => $tipo,
                'costo'              => $costo,
                'costo_diff'         => $costoDiff,
                'k_saldo_ini'        => $kd['saldo_ini'] ?? null,
                'k_entradas'         => $kd['entradas']  ?? null,
                'k_salidas'          => $kd['salidas']   ?? null,
                'k_saldo_fin'        => $kd['saldo_fin'] ?? null,
                'comprobado'         => (bool) $ai->comprobado,
                'comprobado_por'     => $ai->comprobado_por,
                'justificacion'      => $ai->justificacion,
                'justificacion_obs'  => $ai->justificacion_obs,
            ];
        }

        // Excluir productos sin ningún movimiento ni conteo
        $filas = array_values(array_filter($filas, function ($f) {
            $sinKardex = $f['k_saldo_ini'] === null && $f['k_entradas'] === null
                      && $f['k_salidas']   === null && $f['k_saldo_fin'] === null;
            $sinConteo = ($f['conteo'] ?? 0) == 0;
            return !($sinKardex && $sinConteo);
        }));

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
        $perPage = max(1, min(9999, (int) $request->query('per_page', 25)));
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
            ->where('m.sucursal_id', $sucursalId)
            ->where('m.tipo', 'conteo_mensual')
            ->whereRaw("DATE(m.fecha) = ?", [$fecha])
            ->where('m.aud_usuario', '!=', 'sin_contar')
            ->where('p.excluir_estadisticas', false)
            ->select(
                'm.producto_id', 'm.aud_usuario',
                DB::raw("CAST(m.detalle->>'total_contado' AS numeric) AS total_contado"),
                'p.codigo', 'p.nombre', 'p.unidad', 'p.costo',
                'cat.nombre as categoria'
            )
            ->orderBy('m.producto_id')
            ->orderByDesc('m.created_at')
            ->get()
            ->unique('producto_id');

        // ── Kardex BRILO para el período ─────────────────────────────────────
        // Busca el kardex más reciente cuyo FIN PERÍODO caiga dentro de los 5 días
        // anteriores al conteo (cubre casos donde el conteo se registró un día
        // después de medianoche pero el FIN PERÍODO real es el día anterior).
        $kardexMeta = DB::connection('compras')
            ->table('brilo_kardex')
            ->where('sucursal_id', $sucursalId)
            ->where('fecha_hasta', '<=', $fecha)
            ->where('fecha_hasta', '>=', \Carbon\Carbon::parse($fecha)->subDays(5)->format('Y-m-d'))
            ->orderByDesc('fecha_hasta')
            ->orderByDesc('synced_at')
            ->first();

        $kardex     = [];
        $fechaDesde = $kardexMeta?->fecha_desde ?? null;

        if ($kardexMeta) {
            $kRows = DB::connection('compras')
                ->table('brilo_kardex')
                ->where('sucursal_id', $sucursalId)
                ->where('fecha_desde', $kardexMeta->fecha_desde)
                ->where('fecha_hasta', $kardexMeta->fecha_hasta)
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

        // ── Ajustes post-conteo (ajuste/merma aplicados después del conteo) ───
        $ajustesMap = DB::connection('compras')
            ->table('movimientos_inventario')
            ->where('sucursal_id', $sucursalId)
            ->whereIn('tipo', ['ajuste', 'merma'])
            ->whereRaw("DATE(fecha) >= ?", [$fecha])
            ->selectRaw('producto_id, SUM(cantidad_base) as total_ajuste')
            ->groupBy('producto_id')
            ->get()
            ->keyBy('producto_id');

        // ── Construir filas ───────────────────────────────────────────────────
        $filas = [];
        foreach ($movs as $m) {
            $kd      = $kardex[$m->codigo] ?? null;
            // Usar total_contado (cantidad real) y saldo_fin del kardex del período
            $conteo  = $m->total_contado !== null ? round((float) $m->total_contado, 4) : null;
            $brilo   = $kd !== null ? round($kd['saldo_fin'], 4) : null;
            $ajuste  = isset($ajustesMap[$m->producto_id])
                ? round((float) $ajustesMap[$m->producto_id]->total_ajuste, 4)
                : 0.0;
            $conteoEfectivo = $conteo !== null ? round($conteo + $ajuste, 4) : null;
            $diff    = ($conteoEfectivo !== null && $brilo !== null) ? round($conteoEfectivo - $brilo, 4) : null;
            $costo   = $m->costo !== null ? round((float) $m->costo, 4) : null;
            $costoDiff = ($diff !== null && $costo !== null) ? round($diff * $costo, 2) : null;

            if ($brilo === null)               $tipo = 'SIN_BRILO';
            elseif ($diff === null)            $tipo = 'SIN_BRILO';
            elseif (abs($diff) <= 0.01)        $tipo = 'OK';
            elseif ($diff < 0)                 $tipo = 'FALTANTE';
            else                               $tipo = 'SOBRANTE';

            $kSalidas = $kd !== null ? $kd['salidas'] : null;
            $diffPct  = ($diff !== null && $kSalidas !== null && ($kSalidas + abs($diff)) > 0)
                ? round(abs($diff) / (abs($diff) + $kSalidas) * 100, 2)
                : null;

            $filas[] = [
                'producto_id'        => (int) $m->producto_id,
                'codigo'             => $m->codigo,
                'nombre'             => $m->nombre,
                'categoria'          => $m->categoria ?? '—',
                'unidad'             => $m->unidad,
                'conteo'             => $conteo,
                'ajuste_post_conteo' => $ajuste !== 0.0 ? $ajuste : null,
                'brilo'              => $brilo,
                'diff'               => $diff,
                'diff_pct'           => $diffPct,
                'tipo'               => $tipo,
                'costo'              => $costo,
                'costo_diff'         => $costoDiff,
                'k_saldo_ini'        => $kd['saldo_ini'] ?? null,
                'k_entradas'         => $kd['entradas']  ?? null,
                'k_salidas'          => $kd['salidas']   ?? null,
                'k_saldo_fin'        => $kd['saldo_fin'] ?? null,
                'justificacion'      => null,
                'justificacion_obs'  => null,
            ];
        }

        usort($filas, fn($a, $b) => ($a['costo_diff'] ?? 0) <=> ($b['costo_diff'] ?? 0));

        // Ocultar productos sin ningún movimiento real: sin Kardex Y conteo = 0
        $filas = array_values(array_filter($filas, function ($f) {
            $sinKardex = $f['k_saldo_ini'] === null && $f['k_entradas'] === null
                      && $f['k_salidas']   === null && $f['k_saldo_fin'] === null;
            $sinConteo = ($f['conteo'] ?? 0) == 0;
            return !($sinKardex && $sinConteo);
        }));

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
        $perPage = max(1, min(9999, (int) $request->query('per_page', 25)));
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
            ->where('auditor_email', '')  // actualizar solo fila base
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
    // PUT /api/compras/inventario/auditoria-conteo/justificar-directa
    // Guarda justificación para vista sin auditoría (admin directo)
    // Body: { sucursal_id, fecha, producto_id, justificacion, justificacion_obs }
    // ─────────────────────────────────────────────────────────────────────────
    public function guardarJustificacionDirecta(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id'       => 'required|integer',
            'fecha'             => 'required|date',
            'producto_id'       => 'required|integer',
            'justificacion'     => 'nullable|string|max:100',
            'justificacion_obs' => 'nullable|string|max:500',
        ]);

        $now = now();
        DB::connection('compras')
            ->table('inventario_justificaciones')
            ->upsert(
                [[
                    'sucursal_id'       => $request->sucursal_id,
                    'fecha_conteo'      => $request->fecha,
                    'producto_id'       => $request->producto_id,
                    'justificacion'     => $request->justificacion,
                    'justificacion_obs' => $request->justificacion_obs,
                    'created_at'        => $now,
                    'updated_at'        => $now,
                ]],
                ['sucursal_id', 'fecha_conteo', 'producto_id'],
                ['justificacion', 'justificacion_obs', 'updated_at']
            );

        return response()->json(['success' => true]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/compras/inventario/auditoria-conteo/enviar-justificaciones
    // Guarda justificaciones en batch y envía correos a los responsables.
    // Body: { auditoria_id?, sucursal_id, sucursal_nombre, fecha, items: [...] }
    // ─────────────────────────────────────────────────────────────────────────
    public function enviarJustificaciones(Request $request): JsonResponse
    {
        $request->validate([
            'sucursal_id'      => 'required|integer',
            'sucursal_nombre'  => 'required|string',
            'fecha'            => 'required|date',
            'items'            => 'required|array|min:1',
            'items.*.producto_id'       => 'required|integer',
            'items.*.nombre'            => 'required|string',
            'items.*.codigo'            => 'nullable|string',
            'items.*.unidad'            => 'nullable|string',
            'items.*.diferencia'        => 'nullable|numeric',
            'items.*.dif_pct'           => 'nullable|numeric',
            'items.*.costo_diff'        => 'nullable|numeric',
            'items.*.justificacion'     => 'nullable|string|max:100',
            'items.*.justificacion_obs' => 'nullable|string|max:500',
        ]);

        $auditoriaId    = $request->integer('auditoria_id', 0) ?: null;
        $sucursalId     = $request->integer('sucursal_id');
        $sucursalNombre = $request->string('sucursal_nombre');
        $fecha          = $request->string('fecha');
        $gerenteNombre  = Auth::user()?->name ?? 'Gerente';
        $now            = now();

        // 1. Guardar justificaciones en BD
        foreach ($request->items as $item) {
            if ($auditoriaId) {
                DB::connection('compras')
                    ->table('conteo_auditoria_items')
                    ->where('auditoria_id', $auditoriaId)
                    ->where('producto_id', $item['producto_id'])
                    ->where('auditor_email', '')  // actualizar solo fila base
                    ->update([
                        'justificacion'     => $item['justificacion'] ?: null,
                        'justificacion_obs' => $item['justificacion_obs'] ?? null,
                        'updated_at'        => $now,
                    ]);
            } else {
                DB::connection('compras')
                    ->table('inventario_justificaciones')
                    ->upsert(
                        [[
                            'sucursal_id'       => $sucursalId,
                            'fecha_conteo'      => $fecha,
                            'producto_id'       => $item['producto_id'],
                            'justificacion'     => $item['justificacion'] ?: null,
                            'justificacion_obs' => $item['justificacion_obs'] ?? null,
                            'created_at'        => $now,
                            'updated_at'        => $now,
                        ]],
                        ['sucursal_id', 'fecha_conteo', 'producto_id'],
                        ['justificacion', 'justificacion_obs', 'updated_at']
                    );
            }
        }

        // 2. Mapeo justificación → destinatario(s)
        $destinatarios = [
            'kristian@cervezacadejo.com' => [
                'nombre' => 'Kristian Gutierres',
                'tipos'  => ['error_receta', 'codigo_mp_equivocado'],
            ],
            'nelson@cervezacadejo.com' => [
                'nombre' => 'Nelson Martínez',
                'tipos'  => ['error_posteo', 'codigo_mp_equivocado'],
            ],
            'rosamarroquin@cervezacadejo.com' => [
                'nombre' => 'Rosa Marroquín',
                'tipos'  => ['error_traslado'],
            ],
        ];

        $justificacionLabel = [
            'error_receta'          => 'Error en receta',
            'error_posteo'          => 'Error en posteo',
            'error_traslado'        => 'Error en traslado entre sucursales',
            'codigo_mp_equivocado'  => 'Código de materia prima equivocado',
        ];

        // 3. Enviar un correo por producto (un email por item)
        $emailCount = 0;
        foreach ($request->items as $item) {
            $tipo = $item['justificacion'] ?? '';
            if (!$tipo || !isset($justificacionLabel[$tipo])) continue;
            foreach ($destinatarios as $email => $dest) {
                if (!in_array($tipo, $dest['tipos'])) continue;
                Mail::to($email)->send(new JustificacionesInventarioMail(
                    destinatarioNombre:  $dest['nombre'],
                    sucursalNombre:      $sucursalNombre,
                    fechaConteo:         $fecha,
                    gerenteNombre:       $gerenteNombre,
                    item: [
                        'codigo'     => $item['codigo'] ?? '',
                        'nombre'     => $item['nombre'],
                        'unidad'     => $item['unidad'] ?? '',
                        'diferencia' => $item['diferencia'] ?? null,
                        'dif_pct'    => $item['dif_pct'] ?? null,
                        'costo_diff' => $item['costo_diff'] ?? null,
                        'just_label' => $justificacionLabel[$tipo],
                        'obs'        => $item['justificacion_obs'] ?? null,
                    ],
                    tipoResponsabilidad: $tipo,
                ));
                $emailCount++;
            }
        }

        $msg = $emailCount === 0
            ? 'Justificaciones guardadas. No hay items con destinatarios de correo (merma/periodo anterior se guardan sin notificación).'
            : "Justificaciones guardadas. Se enviaron {$emailCount} correo(s).";

        return response()->json(['success' => true, 'message' => $msg, 'correos_enviados' => $emailCount]);
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

        $allRows = DB::connection('compras')
            ->table('conteo_auditoria_items as ai')
            ->leftJoin('productos as p', 'p.id', '=', 'ai.producto_id')
            ->where('ai.auditoria_id', $auditoriaId)
            ->orderBy('ai.producto_nombre')
            ->select('ai.*', 'p.costo')
            ->get();

        // Agrupar por producto_id: fila base (auditor_email='') + filas de auditores
        $byProduct = [];
        foreach ($allRows as $row) {
            $pid = $row->producto_id;
            if (!isset($byProduct[$pid])) {
                $byProduct[$pid] = ['base' => null, 'audits' => []];
            }
            if ($row->auditor_email === '') {
                $byProduct[$pid]['base'] = $row;
            } else {
                $byProduct[$pid]['audits'][] = $row;
            }
        }

        $items = [];
        foreach ($byProduct as $pid => $group) {
            $base   = $group['base'];
            $audits = $group['audits'];
            if (!$base) continue;

            // Consolidar secciones_comprobadas de todos los auditores
            $seccionesConteo = is_string($base->secciones_conteo)
                ? (json_decode($base->secciones_conteo, true) ?? [])
                : (array)($base->secciones_conteo ?? []);

            $consolidado   = [];
            $cantidadTotal = 0.0;
            $ultimoAuditor = null;

            foreach ($audits as $audit) {
                $secComp = is_string($audit->secciones_comprobadas)
                    ? (json_decode($audit->secciones_comprobadas, true) ?? [])
                    : (array)($audit->secciones_comprobadas ?? []);
                foreach ($secComp as $sec => $data) {
                    $consolidado[$sec] = $data;
                }
                $cantidadTotal += (float)($audit->cantidad_auditor ?? 0);
                if ($audit->comprobado_por) $ultimoAuditor = $audit->comprobado_por;
            }

            // comprobado = al menos una sección auditada (el auditor puede corregir la sección real)
            $comprobado = !empty($consolidado);

            // Verificaciones por auditor
            $verificaciones = array_values(array_map(fn($a) => [
                'id'                    => $a->id,
                'auditor_email'         => $a->auditor_email,
                'secciones_comprobadas' => is_string($a->secciones_comprobadas)
                    ? (json_decode($a->secciones_comprobadas, true) ?? (object)[])
                    : ($a->secciones_comprobadas ?? (object)[]),
                'comprobado'            => (bool) $a->comprobado,
                'cantidad_auditor'      => $a->cantidad_auditor,
                'observacion'           => $a->observacion,
                'updated_at'            => $a->updated_at,
            ], $audits));

            $items[] = [
                'id'                    => $base->id,
                'auditoria_id'          => $base->auditoria_id,
                'producto_id'           => (int) $base->producto_id,
                'producto_nombre'       => $base->producto_nombre,
                'cantidad_contador'     => $base->cantidad_contador,
                'unidad'                => $base->unidad,
                'secciones_conteo'      => $seccionesConteo ?: (object)[],
                'costo'                 => $base->costo ?? null,
                // consolidado
                'secciones_comprobadas' => empty($consolidado) ? (object)[] : $consolidado,
                'comprobado'            => $comprobado,
                'comprobado_por'        => $ultimoAuditor,
                'cantidad_auditor'      => $cantidadTotal > 0 ? $cantidadTotal : null,
                'observacion'           => null,
                // por auditor
                'verificaciones'        => $verificaciones,
            ];
        }

        usort($items, fn($a, $b) => strcmp($a['producto_nombre'], $b['producto_nombre']));

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
