<?php

namespace App\Http\Controllers\Api\Operaciones;

use App\Http\Controllers\Controller;
use App\Models\Merma\MermaAuditLog;
use App\Models\Merma\MermaBarrilConectado;
use App\Models\Merma\MermaCerveza;
use App\Models\Merma\MermaCocina;
use App\Models\Merma\MermaConfig;
use App\Models\Merma\MermaEntrada;
use App\Models\Merma\MermaFisica;
use App\Models\Merma\MermaInventario;
use App\Models\Merma\MermaInvItem;
use App\Models\Merma\MermaOtroUso;
use App\Models\Merma\MermaPresentacion;
use App\Models\Merma\MermaVentaBrilo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class MermaBarrilController extends Controller
{
    // ── Catálogos ─────────────────────────────────────────────────────────────

    public function config()
    {
        return response()->json(MermaConfig::first());
    }

    public function updateConfig(Request $request)
    {
        $data = $request->validate([
            'densidad_kg_l'    => 'sometimes|numeric|min:0.5|max:2',
            'tara_pequeno_lb'  => 'sometimes|numeric|min:0',
            'tara_grande_lb'   => 'sometimes|numeric|min:0',
            'barril_pequeno_oz'=> 'sometimes|numeric|min:0',
            'barril_grande_oz' => 'sometimes|numeric|min:0',
            'semaforo_normal'  => 'sometimes|numeric|min:0|max:100',
            'semaforo_revisar' => 'sometimes|numeric|min:0|max:100',
            'semaforo_alerta'  => 'sometimes|numeric|min:0|max:100',
            'meta_pct'         => 'sometimes|numeric|min:0|max:100',
        ]);

        $cfg = MermaConfig::firstOrNew([]);
        $cfg->fill($data);
        $cfg->updated_by = auth()->id();
        $cfg->save();

        return response()->json($cfg);
    }

    public function presentaciones()
    {
        return response()->json(
            MermaPresentacion::where('activa', true)->orderBy('orden')->get()
        );
    }

    public function cervezas()
    {
        return response()->json(
            MermaCerveza::activas()->orderBy('orden')->get()
        );
    }

    public function cervezasAll()
    {
        return response()->json(MermaCerveza::orderBy('orden')->get());
    }

    public function storeCerveza(Request $request)
    {
        $data = $request->validate([
            'nombre'         => 'required|string|max:100',
            'estilo'         => 'nullable|string|max:100',
            'color_hex'      => 'nullable|string|max:10',
            'estado'         => 'in:activo,temporada,inactivo',
            'vigencia_desde' => 'nullable|date',
            'vigencia_hasta' => 'nullable|date',
            'orden'          => 'nullable|integer',
        ]);

        return response()->json(MermaCerveza::create($data), 201);
    }

    public function updateCerveza(Request $request, $id)
    {
        $cerveza = MermaCerveza::findOrFail($id);
        $data = $request->validate([
            'nombre'         => 'sometimes|string|max:100',
            'estilo'         => 'nullable|string|max:100',
            'color_hex'      => 'nullable|string|max:10',
            'estado'         => 'sometimes|in:activo,temporada,inactivo',
            'vigencia_desde' => 'nullable|date',
            'vigencia_hasta' => 'nullable|date',
            'orden'          => 'nullable|integer',
        ]);

        $cerveza->update($data);
        return response()->json($cerveza);
    }

    // ── Inventario ────────────────────────────────────────────────────────────

    public function inventarioHoy(Request $request)
    {
        $sucursalId = $request->query('sucursal_id') ?? $request->user()->sucursal_id ?? null;

        $withs = [
            'items.cerveza',
            'items.barrilesConectados',
            'entradas',
            'fisica',
            'cocina.cerveza',
            'otrosUsos.cerveza',
            'ventasBrilo',
        ];

        // Primero intentar el inventario de hoy
        $inv = MermaInventario::with($withs)
            ->where('sucursal_id', $sucursalId)
            ->where('fecha', today())
            ->first();

        // Si no hay inventario de hoy, buscar el borrador más reciente de ayer
        // (para restaurantes que trabajan hasta después de medianoche)
        if (!$inv) {
            $inv = MermaInventario::with($withs)
                ->where('sucursal_id', $sucursalId)
                ->where('fecha', today()->subDay())
                ->where('estado', 'borrador')
                ->first();
        }

        if ($inv) {
            $inv->primer_dia    = $this->esPrimerDia($inv->sucursal_id, $inv->fecha);
            $inv->usuario_nombre = $this->resolverNombreUsuario($inv->usuario_id);
        }

        return response()->json($inv);
    }

    // Lista de inventarios recientes incluyendo borradores (para retomar días incompletos)
    public function historial(Request $request)
    {
        $sucursalId = $request->query('sucursal_id');
        $limite     = min((int) ($request->query('limit', 30)), 90);

        $rows = MermaInventario::select('id', 'sucursal_id', 'fecha', 'estado', 'hora_inicio', 'hora_cierre')
            ->when($sucursalId, fn ($q) => $q->where('sucursal_id', $sucursalId))
            ->orderByDesc('fecha')
            ->limit($limite)
            ->get();

        return response()->json($rows);
    }

    public function show($id)
    {
        $inv = MermaInventario::with([
            'items.cerveza',
            'items.barrilesConectados',
            'entradas',
            'fisica',
            'cocina.cerveza',
            'otrosUsos.cerveza',
            'ventasBrilo',
            'auditLog',
        ])->findOrFail($id);

        $inv->primer_dia     = $this->esPrimerDia($inv->sucursal_id, $inv->fecha);
        $inv->usuario_nombre = $this->resolverNombreUsuario($inv->usuario_id);

        return response()->json($inv);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sucursal_id' => 'required|integer',
            'fecha'       => 'sometimes|date',
        ]);

        $fecha      = $data['fecha'] ?? today()->toDateString();
        $sucursalId = $data['sucursal_id'];

        // No abrir dos inventarios el mismo día para la misma sucursal
        $exists = MermaInventario::where('sucursal_id', $sucursalId)
                                 ->where('fecha', $fecha)
                                 ->first();
        if ($exists) {
            return response()->json($exists);
        }

        DB::connection('compras')->beginTransaction();
        try {
            $inv = MermaInventario::create([
                'sucursal_id' => $sucursalId,
                'fecha'       => $fecha,
                'usuario_id'  => auth()->id(),
                'hora_inicio' => now()->format('H:i:s'),
                'estado'      => 'borrador',
            ]);

            // Inventario de cierre más reciente de esta sucursal (para heredar inicial_oz)
            $invAnterior = MermaInventario::where('sucursal_id', $sucursalId)
                ->where('fecha', '<', $fecha)
                ->whereIn('estado', ['enviado', 'aprobado'])
                ->orderBy('fecha', 'desc')
                ->with('items')
                ->first();

            $finalOzAnterior = [];
            if ($invAnterior) {
                foreach ($invAnterior->items as $itemAnt) {
                    // final_oz guardado explícitamente tiene prioridad;
                    // si no existe, calculamos desde barriles cerrados con capacidades default
                    $finalOzAnterior[$itemAnt->cerveza_id] = $itemAnt->final_oz
                        ?? ($itemAnt->final_cerrados_p * 661 + $itemAnt->final_cerrados_g * 1986.26);
                }
            }

            // Crear un item por cada cerveza activa heredando el inventario de cierre de ayer
            $cervezas = MermaCerveza::activas()->orderBy('orden')->get();
            foreach ($cervezas as $c) {
                MermaInvItem::create([
                    'inventario_id'    => $inv->id,
                    'cerveza_id'       => $c->id,
                    'inicial_oz'       => $finalOzAnterior[$c->id] ?? 0,
                    'final_cerrados_p' => 0,
                    'final_cerrados_g' => 0,
                ]);
            }

            // Merma física vacía
            MermaFisica::create([
                'inventario_id' => $inv->id,
                'cantidad'      => 0,
                'unidad'        => 'L',
                'oz_calculado'  => 0,
                'confirmada'    => false,
            ]);

            $this->log($inv->id, auth()->id(), $request->user()?->name, $sucursalId, null,
                       'Apertura de inventario', null, $fecha, null);

            DB::connection('compras')->commit();
        } catch (\Throwable $e) {
            DB::connection('compras')->rollBack();
            throw $e;
        }

        $invFresh = MermaInventario::with([
            'items.cerveza', 'items.barrilesConectados',
            'entradas', 'fisica', 'cocina', 'otrosUsos', 'ventasBrilo',
        ])->find($inv->id);
        $invFresh->primer_dia = $this->esPrimerDia($sucursalId, $fecha);

        return response()->json($invFresh, 201);
    }

    // Sincroniza items de cervezas activas a un inventario que quedó sin ellos,
    // heredando el final_oz del último inventario cerrado de la misma sucursal.
    public function syncItems(Request $request, $id)
    {
        $inv      = MermaInventario::findOrFail($id);
        $cervezas = MermaCerveza::activas()->orderBy('orden')->get();
        $existing = MermaInvItem::where('inventario_id', $id)->pluck('cerveza_id')->toArray();

        // Heredar inventario de cierre anterior (igual que store())
        $invAnterior = MermaInventario::where('sucursal_id', $inv->sucursal_id)
            ->where('fecha', '<=', $inv->fecha)
            ->where('id', '!=', $inv->id)
            ->whereIn('estado', ['enviado', 'aprobado'])
            ->orderBy('fecha', 'desc')
            ->with('items')
            ->first();

        $finalOzAnterior = [];
        if ($invAnterior) {
            foreach ($invAnterior->items as $itemAnt) {
                $finalOzAnterior[$itemAnt->cerveza_id] = $itemAnt->final_oz
                    ?? ($itemAnt->final_cerrados_p * 661 + $itemAnt->final_cerrados_g * 1986.26);
            }
        }

        $added = 0;
        foreach ($cervezas as $c) {
            if (!in_array($c->id, $existing)) {
                MermaInvItem::create([
                    'inventario_id'    => $id,
                    'cerveza_id'       => $c->id,
                    'inicial_oz'       => $finalOzAnterior[$c->id] ?? 0,
                    'final_cerrados_p' => 0,
                    'final_cerrados_g' => 0,
                ]);
                $added++;
            }
        }
        return response()->json([
            'success' => true,
            'added'   => $added,
            'inv'     => MermaInventario::with([
                'items.cerveza', 'items.barrilesConectados',
                'entradas', 'fisica', 'cocina', 'otrosUsos', 'ventasBrilo',
            ])->find($id),
        ]);
    }

    public function cerrar(Request $request, $id)
    {
        $inv = MermaInventario::findOrFail($id);

        if ($inv->estado !== 'borrador') {
            return response()->json(['message' => 'Solo se puede cerrar un inventario en borrador'], 422);
        }

        $inv->update([
            'hora_cierre' => now()->format('H:i:s'),
            'estado'      => 'enviado',
        ]);

        $this->log($id, auth()->id(), $request->user()?->name, $inv->sucursal_id, null,
                   'Cierre de inventario — enviado a aprobación',
                   null, 'enviado', null);

        return response()->json($inv);
    }

    public function aprobar(Request $request, $id)
    {
        $inv = MermaInventario::findOrFail($id);

        if ($inv->estado !== 'enviado') {
            return response()->json(['message' => 'Solo se puede aprobar un inventario enviado'], 422);
        }

        $inv->update([
            'estado'       => 'aprobado',
            'aprobado_por' => auth()->id(),
            'aprobado_at'  => now(),
        ]);

        $this->log($id, auth()->id(), $request->user()?->name, $inv->sucursal_id, null,
                   'Inventario aprobado', null, 'aprobado', null);

        return response()->json($inv);
    }

    // ── Items (por cerveza) ───────────────────────────────────────────────────

    public function updateItem(Request $request, $invId, $itemId)
    {
        $item = MermaInvItem::where('inventario_id', $invId)->findOrFail($itemId);

        $data = $request->validate([
            'inicial_oz'       => 'sometimes|numeric|min:0',
            'final_cerrados_p' => 'sometimes|integer|min:0',
            'final_cerrados_g' => 'sometimes|integer|min:0',
            'final_oz'         => 'sometimes|nullable|numeric|min:0',
        ]);

        // Auditar corrección de inicial si cambia
        if (isset($data['inicial_oz']) && (float)$item->inicial_oz !== (float)$data['inicial_oz']) {
            $motivo = $request->input('motivo', '');
            $this->log($invId, auth()->id(), $request->user()?->name, null, null,
                       "Corrección inicial — {$item->cerveza?->nombre}",
                       number_format($item->inicial_oz, 2).' oz',
                       number_format($data['inicial_oz'], 2).' oz',
                       $motivo);
        }

        $item->update($data);
        return response()->json($item->load('barrilesConectados'));
    }

    // ── Barriles conectados ───────────────────────────────────────────────────

    public function storeKeg(Request $request, $invId, $itemId)
    {
        $item = MermaInvItem::where('inventario_id', $invId)->findOrFail($itemId);
        $data = $request->validate([
            'tamanio' => 'required|in:p,g',
            'peso_lb' => 'required|numeric|min:0',
        ]);

        $keg = MermaBarrilConectado::create(['item_id' => $item->id] + $data);
        return response()->json($keg, 201);
    }

    public function updateKeg(Request $request, $invId, $itemId, $kegId)
    {
        $keg = MermaBarrilConectado::findOrFail($kegId);
        $data = $request->validate([
            'tamanio' => 'sometimes|in:p,g',
            'peso_lb' => 'sometimes|numeric|min:0',
        ]);
        $keg->update($data);
        return response()->json($keg);
    }

    public function destroyKeg($invId, $itemId, $kegId)
    {
        MermaBarrilConectado::findOrFail($kegId)->delete();
        return response()->json(null, 204);
    }

    // ── Entradas ──────────────────────────────────────────────────────────────

    public function storeEntrada(Request $request, $invId)
    {
        if (!is_numeric($invId)) {
            return response()->json(['message' => 'ID de inventario inválido'], 422);
        }
        $data = $request->validate([
            'cerveza_id'  => 'required|exists:compras.merma_cervezas,id',
            'tamanio'     => 'required|in:p,g',
            'cantidad'    => 'required|integer|min:1',
            'hora_ingreso'=> 'nullable|date_format:H:i',
        ]);

        $entrada = MermaEntrada::create(['inventario_id' => $invId] + $data);
        return response()->json($entrada, 201);
    }

    public function destroyEntrada($invId, $entradaId)
    {
        MermaEntrada::where('inventario_id', $invId)->findOrFail($entradaId)->delete();
        return response()->json(null, 204);
    }

    // ── Merma física ─────────────────────────────────────────────────────────

    public function updateFisica(Request $request, $invId)
    {
        $data = $request->validate([
            'cantidad'     => 'required|numeric|min:0',
            'unidad'       => 'required|in:L,ml,oz',
            'oz_calculado' => 'required|numeric|min:0',
            'confirmada'   => 'sometimes|boolean',
        ]);

        $fisica = MermaFisica::where('inventario_id', $invId)->firstOrNew(['inventario_id' => $invId]);
        $fisica->fill($data);
        $fisica->updated_at = now();
        $fisica->save();

        if ($data['confirmada'] ?? false) {
            $inv = MermaInventario::find($invId);
            $this->log($invId, auth()->id(), $request->user()?->name, $inv?->sucursal_id, null,
                       'Merma física · confirmación unidad',
                       null,
                       $data['cantidad'].' '.$data['unidad'].' = '.round($data['oz_calculado']).' oz',
                       'Confirmado por el usuario');
        }

        return response()->json($fisica);
    }

    // ── Cocina ────────────────────────────────────────────────────────────────

    public function storeCocina(Request $request, $invId)
    {
        if (!is_numeric($invId)) {
            return response()->json(['message' => 'ID de inventario inválido'], 422);
        }
        $data = $request->validate([
            'cerveza_id'      => 'required|exists:compras.merma_cervezas,id',
            'cantidad'        => 'required|numeric|min:0',
            'unidad'          => 'required|in:L,ml,oz',
            'oz_calculado'    => 'required|numeric|min:0',
            'motivo'          => 'nullable|string|max:500',
            'hora'            => 'nullable|date_format:H:i',
            'usuario_captura' => 'nullable|string|max:150',
        ]);

        $mov = MermaCocina::create([
            'inventario_id' => $invId,
            'created_at'    => now(),
        ] + $data);

        return response()->json($mov->load('cerveza'), 201);
    }

    public function destroyCocina($invId, $cocinaId)
    {
        MermaCocina::where('inventario_id', $invId)->findOrFail($cocinaId)->delete();
        return response()->json(null, 204);
    }

    // ── Otros usos ────────────────────────────────────────────────────────────

    public function storeOtroUso(Request $request, $invId)
    {
        if (!is_numeric($invId)) {
            return response()->json(['message' => 'ID de inventario inválido'], 422);
        }
        $data = $request->validate([
            'cerveza_id'      => 'required|exists:compras.merma_cervezas,id',
            'cantidad'        => 'required|numeric|min:0',
            'unidad'          => 'required|in:L,ml,oz',
            'oz_calculado'    => 'required|numeric|min:0',
            'categoria'       => 'required|string|max:100',
            'detalle'         => 'nullable|string|max:1000',
            'usuario_captura' => 'nullable|string|max:150',
        ]);

        $uso = MermaOtroUso::create([
            'inventario_id' => $invId,
            'created_at'    => now(),
        ] + $data);

        return response()->json($uso->load('cerveza'), 201);
    }

    public function destroyOtroUso($invId, $usoId)
    {
        MermaOtroUso::where('inventario_id', $invId)->findOrFail($usoId)->delete();
        return response()->json(null, 204);
    }

    // ── Ventas Brilo (solo lectura) ────────────────────────────────────────────

    public function ventasBrilo(Request $request, $invId)
    {
        // Buscar por inventario_id (el script sync lo vincula directamente)
        $ventas = MermaVentaBrilo::where('inventario_id', $invId)->get();

        return response()->json($ventas);
    }

    // ── Audit log ─────────────────────────────────────────────────────────────

    public function auditLog($invId)
    {
        $logs = MermaAuditLog::where('inventario_id', $invId)
                             ->orderByDesc('created_at')
                             ->get();
        return response()->json($logs);
    }

    // ── Dashboard gerencial ───────────────────────────────────────────────────

    public function dashboard(Request $request)
    {
        $desde      = $request->query('desde', today()->startOfMonth()->toDateString());
        $hasta      = $request->query('hasta', today()->toDateString());
        $sucursalId = $request->query('sucursal_id');

        $invs = DB::connection('compras')
            ->table('merma_inventarios')
            ->select('id', 'fecha')
            ->whereBetween('fecha', [$desde, $hasta])
            ->whereIn('estado', ['enviado', 'aprobado'])
            ->when($sucursalId, fn($q) => $q->where('sucursal_id', $sucursalId))
            ->get();

        if ($invs->isEmpty()) {
            return response()->json([
                'dias_registrados'   => 0,
                'total_fisico_oz'    => 0,
                'pct_merma_promedio' => 0,
                'peor_dia'           => null,
                'por_dia'            => [],
            ]);
        }

        $ids = $invs->pluck('id')->toArray();

        $fisicos = DB::connection('compras')->table('merma_inv_items')
            ->selectRaw('inventario_id, SUM(inicial_oz) AS oz')
            ->whereIn('inventario_id', $ids)
            ->groupBy('inventario_id')->pluck('oz', 'inventario_id');

        $ventas = DB::connection('compras')->table('merma_ventas_brilo')
            ->selectRaw('inventario_id, SUM(oz_efectivas) AS oz')
            ->whereIn('inventario_id', $ids)
            ->groupBy('inventario_id')->pluck('oz', 'inventario_id');

        $cocina = DB::connection('compras')->table('merma_cocina')
            ->selectRaw('inventario_id, SUM(oz_calculado) AS oz')
            ->whereIn('inventario_id', $ids)
            ->groupBy('inventario_id')->pluck('oz', 'inventario_id');

        $otros = DB::connection('compras')->table('merma_otros_usos')
            ->selectRaw('inventario_id, SUM(oz_calculado) AS oz')
            ->whereIn('inventario_id', $ids)
            ->groupBy('inventario_id')->pluck('oz', 'inventario_id');

        $mfisica = DB::connection('compras')->table('merma_fisica')
            ->selectRaw('inventario_id, oz_calculado AS oz')
            ->whereIn('inventario_id', $ids)
            ->pluck('oz', 'inventario_id');

        $porDia = $invs->map(function ($inv) use ($fisicos, $ventas, $cocina, $otros, $mfisica) {
            $fisicoOz = (float)($fisicos[$inv->id] ?? 0);
            $ventasOz = (float)($ventas[$inv->id]  ?? 0);
            $cocinaOz = (float)($cocina[$inv->id]  ?? 0);
            $otrosOz  = (float)($otros[$inv->id]   ?? 0);
            $mfOz     = (float)($mfisica[$inv->id] ?? 0);
            $netaOz   = $fisicoOz - $ventasOz - $cocinaOz - $otrosOz - $mfOz;
            $pct      = $fisicoOz > 0 ? round($netaOz / $fisicoOz * 100, 2) : 0;

            return [
                'fecha'     => is_string($inv->fecha) ? $inv->fecha : (string)$inv->fecha,
                'fisico_oz' => round($fisicoOz, 1),
                'ventas_oz' => round($ventasOz, 1),
                'neta_oz'   => round($netaOz,   1),
                'pct'       => $pct,
            ];
        })->sortByDesc('fecha')->values();

        $totalFisico = $porDia->sum('fisico_oz');
        $pctPromedio = $porDia->count() > 0 ? round($porDia->avg('pct'), 2) : 0;
        $peorDia     = $porDia->sortByDesc('pct')->first();

        return response()->json([
            'dias_registrados'   => $porDia->count(),
            'total_fisico_oz'    => round($totalFisico, 1),
            'pct_merma_promedio' => $pctPromedio,
            'peor_dia'           => $peorDia
                ? ['fecha' => $peorDia['fecha'], 'pct' => $peorDia['pct']]
                : null,
            'por_dia'            => $porDia,
        ]);
    }

    // ── Estadísticas multi-sucursal ───────────────────────────────────────────

    public function estadisticas(Request $request)
    {
        $desde      = $request->query('desde', today()->subDays(29)->toDateString());
        $hasta      = $request->query('hasta', today()->toDateString());
        $sucursalId = $request->query('sucursal_id');

        $invs = DB::connection('compras')
            ->table('merma_inventarios')
            ->select('id', 'sucursal_id', 'fecha', 'hora_inicio', 'hora_cierre')
            ->whereBetween('fecha', [$desde, $hasta])
            ->whereIn('estado', ['enviado', 'aprobado'])
            ->when($sucursalId, fn($q) => $q->where('sucursal_id', $sucursalId))
            ->orderBy('fecha')
            ->get();

        if ($invs->isEmpty()) {
            return response()->json([
                'por_sucursal' => [], 'tendencia' => [], 'global' => null,
                'causas' => null, 'por_cerveza' => [], 'por_dia_semana' => [],
                'eficiencia' => null, 'vs_anterior' => null,
            ]);
        }

        $ids = $invs->pluck('id')->toArray();

        $cfg = DB::connection('compras')->table('merma_config')->first();
        $ozP = (float)($cfg->barril_pequeno_oz ?? 1984);
        $ozG = (float)($cfg->barril_grande_oz  ?? 3968);

        $fisicos = DB::connection('compras')->table('merma_inv_items')
            ->selectRaw('inventario_id, SUM(inicial_oz) AS oz')
            ->whereIn('inventario_id', $ids)
            ->groupBy('inventario_id')->pluck('oz', 'inventario_id');

        $finales = DB::connection('compras')->table('merma_inv_items')
            ->selectRaw('inventario_id, SUM(final_oz) AS oz')
            ->whereIn('inventario_id', $ids)
            ->groupBy('inventario_id')->pluck('oz', 'inventario_id');

        // Entradas convertidas a oz usando la config
        $entradasRaw = DB::connection('compras')->table('merma_entradas')
            ->selectRaw("inventario_id, tamanio, SUM(cantidad) AS cnt")
            ->whereIn('inventario_id', $ids)
            ->groupBy('inventario_id', 'tamanio')->get();
        $entradas = collect();
        foreach ($entradasRaw->groupBy('inventario_id') as $invId => $rows) {
            $oz = 0;
            foreach ($rows as $r) {
                $oz += $r->tamanio === 'p' ? $r->cnt * $ozP : $r->cnt * $ozG;
            }
            $entradas[$invId] = $oz;
        }

        $ventas = DB::connection('compras')->table('merma_ventas_brilo')
            ->selectRaw('inventario_id, SUM(oz_efectivas) AS oz')
            ->whereIn('inventario_id', $ids)
            ->groupBy('inventario_id')->pluck('oz', 'inventario_id');

        $cocina = DB::connection('compras')->table('merma_cocina')
            ->selectRaw('inventario_id, SUM(oz_calculado) AS oz')
            ->whereIn('inventario_id', $ids)
            ->groupBy('inventario_id')->pluck('oz', 'inventario_id');

        $otros = DB::connection('compras')->table('merma_otros_usos')
            ->selectRaw('inventario_id, SUM(oz_calculado) AS oz')
            ->whereIn('inventario_id', $ids)
            ->groupBy('inventario_id')->pluck('oz', 'inventario_id');

        $mfisica = DB::connection('compras')->table('merma_fisica')
            ->selectRaw('inventario_id, oz_calculado AS oz')
            ->whereIn('inventario_id', $ids)
            ->pluck('oz', 'inventario_id');

        $itemsPorCerveza = DB::connection('compras')->table('merma_inv_items')
            ->selectRaw('cerveza_id, SUM(inicial_oz) AS oz')
            ->whereIn('inventario_id', $ids)
            ->groupBy('cerveza_id')->get()->keyBy('cerveza_id');

        $ventasPorCerveza = DB::connection('compras')->table('merma_ventas_brilo')
            ->selectRaw('cerveza_id, SUM(oz_efectivas) AS oz')
            ->whereNotNull('cerveza_id')
            ->whereIn('inventario_id', $ids)
            ->groupBy('cerveza_id')->get()->keyBy('cerveza_id');

        $cervezas          = DB::connection('compras')->table('merma_cervezas')
            ->select('id', 'nombre', 'color_hex', 'estilo')->orderBy('orden')->get();
        $sucursalesNombres = \App\Models\Sucursal::select('id', 'nombre')->pluck('nombre', 'id');

        // ── Rows por inventario ──────────────────────────────────────────
        $rows = $invs->map(function ($inv) use ($fisicos, $finales, $entradas, $ventas, $cocina, $otros, $mfisica) {
            $inicialOz   = (float)($fisicos[$inv->id]  ?? 0);
            $entradasOz  = (float)($entradas[$inv->id] ?? 0);
            $finalOz     = (float)($finales[$inv->id]  ?? 0);
            $fisicoOz    = $inicialOz + $entradasOz;   // stock disponible total
            $ventasOz    = (float)($ventas[$inv->id]   ?? 0);
            $cocinaOz    = (float)($cocina[$inv->id]   ?? 0);
            $otrosOz     = (float)($otros[$inv->id]    ?? 0);
            $mfOz        = (float)($mfisica[$inv->id]  ?? 0);
            // merma no explicada = disponible - ventas - cocina - otros - mfisica - final
            $netaOz      = $fisicoOz - $ventasOz - $cocinaOz - $otrosOz - $mfOz - $finalOz;
            $pct         = $fisicoOz > 0 ? round($netaOz / $fisicoOz * 100, 2) : 0;

            $durMinutos = null;
            if ($inv->hora_inicio && $inv->hora_cierre) {
                $t1 = strtotime($inv->hora_inicio);
                $t2 = strtotime($inv->hora_cierre);
                if ($t2 > $t1) {
                    $durMinutos = (int)round(($t2 - $t1) / 60);
                }
            }

            return [
                'inv_id'      => $inv->id,
                'sucursal_id' => $inv->sucursal_id,
                'fecha'       => is_string($inv->fecha) ? $inv->fecha : (string)$inv->fecha,
                'fisico_oz'   => round($fisicoOz, 1),
                'ventas_oz'   => round($ventasOz, 1),
                'cocina_oz'   => round($cocinaOz, 1),
                'otros_oz'    => round($otrosOz, 1),
                'mfisica_oz'  => round($mfOz, 1),
                'neta_oz'     => round($netaOz, 1),
                'pct'         => $pct,
                'dur_minutos' => $durMinutos,
            ];
        });

        // ── Por sucursal ─────────────────────────────────────────────────
        $porSucursal = $rows->groupBy('sucursal_id')->map(function ($diasSuc, $sucId) use ($sucursalesNombres) {
            $pctProm = round($diasSuc->avg('pct'), 2);
            $peor    = $diasSuc->sortByDesc('pct')->first();
            return [
                'sucursal_id'  => $sucId,
                'nombre'       => $sucursalesNombres[$sucId] ?? "Sucursal $sucId",
                'dias'         => $diasSuc->count(),
                'pct_promedio' => $pctProm,
                'fisico_oz'    => round($diasSuc->sum('fisico_oz'), 1),
                'ventas_oz'    => round($diasSuc->sum('ventas_oz'), 1),
                'neta_oz'      => round($diasSuc->sum('neta_oz'), 1),
                'peor_dia'     => $peor ? ['fecha' => $peor['fecha'], 'pct' => $peor['pct']] : null,
            ];
        })->sortBy('pct_promedio')->values();

        // ── Tendencia ────────────────────────────────────────────────────
        $tendencia = $rows->groupBy('fecha')->map(function ($diasFecha, $fecha) {
            return [
                'fecha'  => $fecha,
                'puntos' => $diasFecha->map(fn($d) => [
                    'sucursal_id' => $d['sucursal_id'],
                    'pct'         => $d['pct'],
                ])->values(),
            ];
        })->sortBy('fecha')->values();

        // ── Global ───────────────────────────────────────────────────────
        $totalDias = $rows->count();
        $globalPct = $totalDias > 0 ? round($rows->avg('pct'), 2) : 0;
        $mejor     = $porSucursal->first();
        $peorSuc   = $porSucursal->last();

        // ── Causas ───────────────────────────────────────────────────────
        $totalFisico  = round((float)$rows->sum('fisico_oz'), 1);
        $causas = [
            'fisico_oz'  => $totalFisico,
            'breakdown'  => array_values(array_filter([
                ['causa' => 'No explicada', 'oz' => round((float)$rows->sum('neta_oz'), 1),
                 'pct' => $totalFisico > 0 ? round((float)$rows->sum('neta_oz') / $totalFisico * 100, 2) : 0],
                ['causa' => 'Cocina',       'oz' => round((float)$rows->sum('cocina_oz'), 1),
                 'pct' => $totalFisico > 0 ? round((float)$rows->sum('cocina_oz') / $totalFisico * 100, 2) : 0],
                ['causa' => 'Otros usos',   'oz' => round((float)$rows->sum('otros_oz'), 1),
                 'pct' => $totalFisico > 0 ? round((float)$rows->sum('otros_oz') / $totalFisico * 100, 2) : 0],
                ['causa' => 'Merma física', 'oz' => round((float)$rows->sum('mfisica_oz'), 1),
                 'pct' => $totalFisico > 0 ? round((float)$rows->sum('mfisica_oz') / $totalFisico * 100, 2) : 0],
            ])),
        ];

        // ── Por cerveza ──────────────────────────────────────────────────
        $porCerveza = $cervezas->map(function ($c) use ($itemsPorCerveza, $ventasPorCerveza, $totalFisico) {
            $ozFisico = (float)($itemsPorCerveza[$c->id]->oz ?? 0);
            if ($ozFisico == 0) return null;
            $ozVentas = (float)($ventasPorCerveza[$c->id]->oz ?? 0);
            return [
                'cerveza_id'    => $c->id,
                'nombre'        => $c->nombre,
                'color_hex'     => $c->color_hex,
                'estilo'        => $c->estilo,
                'fisico_oz'     => round($ozFisico, 1),
                'ventas_oz'     => round($ozVentas, 1),
                'pct_del_total' => $totalFisico > 0 ? round($ozFisico / $totalFisico * 100, 2) : 0,
            ];
        })->filter()->sortByDesc('fisico_oz')->values();

        // ── Por día de semana ────────────────────────────────────────────
        $diasNombres = ['', 'Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'];
        $porDiaSemana = $rows->groupBy(function ($r) {
            return (int)date('N', strtotime($r['fecha']));
        })->map(function ($g, $dow) use ($diasNombres) {
            return [
                'dow'          => $dow,
                'nombre'       => $diasNombres[$dow] ?? "Día $dow",
                'registros'    => $g->count(),
                'pct_promedio' => round((float)$g->avg('pct'), 2),
                'neta_oz'      => round((float)$g->sum('neta_oz'), 1),
            ];
        })->sortBy('dow')->values();

        // ── Eficiencia ────────────────────────────────────────────────────
        $conCierre  = $rows->filter(fn($r) => $r['dur_minutos'] !== null);
        $eficiencia = [
            'total_inventarios' => $totalDias,
            'con_cierre'        => $conCierre->count(),
            'adherencia_pct'    => $totalDias > 0 ? round($conCierre->count() / $totalDias * 100, 1) : 0,
            'dur_promedio_min'  => $conCierre->count() > 0 ? (int)round((float)$conCierre->avg('dur_minutos')) : null,
            'sucursales_sin_cierre' => $rows->filter(fn($r) => $r['dur_minutos'] === null)
                ->groupBy('sucursal_id')
                ->map(fn($g, $id) => ['sucursal_id' => $id, 'dias_sin_cierre' => $g->count()])
                ->values(),
        ];

        // ── Vs período anterior ───────────────────────────────────────────
        $diasPeriodo = \Carbon\Carbon::parse($desde)->diffInDays(\Carbon\Carbon::parse($hasta)) + 1;
        $antHasta    = \Carbon\Carbon::parse($desde)->subDay()->toDateString();
        $antDesde    = \Carbon\Carbon::parse($antHasta)->subDays($diasPeriodo - 1)->toDateString();

        $idsAnt = DB::connection('compras')->table('merma_inventarios')
            ->selectRaw('id')
            ->whereBetween('fecha', [$antDesde, $antHasta])
            ->whereIn('estado', ['enviado', 'aprobado'])
            ->when($sucursalId, fn($q) => $q->where('sucursal_id', $sucursalId))
            ->pluck('id')->toArray();

        $vsAnterior = null;
        if (!empty($idsAnt)) {
            $inicialAnt  = (float)DB::connection('compras')->table('merma_inv_items')
                ->whereIn('inventario_id', $idsAnt)->sum('inicial_oz');
            $finalAnt    = (float)DB::connection('compras')->table('merma_inv_items')
                ->whereIn('inventario_id', $idsAnt)->sum('final_oz');
            $entRawAnt   = DB::connection('compras')->table('merma_entradas')
                ->selectRaw("tamanio, SUM(cantidad) AS cnt")
                ->whereIn('inventario_id', $idsAnt)
                ->groupBy('tamanio')->get();
            $entradasAnt = 0;
            foreach ($entRawAnt as $r) {
                $entradasAnt += $r->tamanio === 'p' ? $r->cnt * $ozP : $r->cnt * $ozG;
            }
            $fisicoAnt   = $inicialAnt + $entradasAnt;
            $ventasAnt   = (float)DB::connection('compras')->table('merma_ventas_brilo')
                ->whereIn('inventario_id', $idsAnt)->sum('oz_efectivas');
            $cocinaAnt   = (float)DB::connection('compras')->table('merma_cocina')
                ->whereIn('inventario_id', $idsAnt)->sum('oz_calculado');
            $otrosAnt    = (float)DB::connection('compras')->table('merma_otros_usos')
                ->whereIn('inventario_id', $idsAnt)->sum('oz_calculado');
            $mfAnt       = (float)DB::connection('compras')->table('merma_fisica')
                ->whereIn('inventario_id', $idsAnt)->sum('oz_calculado');

            $netaAnt  = $fisicoAnt - $ventasAnt - $cocinaAnt - $otrosAnt - $mfAnt - $finalAnt;
            $pctAnt   = $fisicoAnt > 0 ? round($netaAnt / $fisicoAnt * 100, 2) : 0;

            $vsAnterior = [
                'desde'        => $antDesde,
                'hasta'        => $antHasta,
                'dias'         => count($idsAnt),
                'pct_promedio' => $pctAnt,
                'neta_oz'      => round($netaAnt, 1),
                'fisico_oz'    => round($fisicoAnt, 1),
                'delta_pct'    => round($globalPct - $pctAnt, 2),
            ];
        }

        $detalleDiario = $rows->map(fn($r) => [
            'fecha'      => $r['fecha'],
            'sucursal'   => $sucursalesNombres[$r['sucursal_id']] ?? "Suc {$r['sucursal_id']}",
            'fisico_oz'  => $r['fisico_oz'],
            'ventas_oz'  => $r['ventas_oz'],
            'neta_oz'    => $r['neta_oz'],
            'cocina_oz'  => $r['cocina_oz'],
            'otros_oz'   => $r['otros_oz'],
            'mfisica_oz' => $r['mfisica_oz'],
            'pct'        => $r['pct'],
            'dur_min'    => $r['dur_minutos'],
        ])->sortBy(['fecha', 'sucursal'])->values();

        return response()->json([
            'por_sucursal'   => $porSucursal,
            'tendencia'      => $tendencia,
            'detalle_diario' => $detalleDiario,
            'global'         => [
                'dias_total'     => $totalDias,
                'pct_promedio'   => $globalPct,
                'mejor_sucursal' => $mejor   ? ['nombre' => $mejor['nombre'],   'pct' => $mejor['pct_promedio']]   : null,
                'peor_sucursal'  => $peorSuc  ? ['nombre' => $peorSuc['nombre'],  'pct' => $peorSuc['pct_promedio']]  : null,
            ],
            'causas'         => $causas,
            'por_cerveza'    => $porCerveza,
            'por_dia_semana' => $porDiaSemana,
            'eficiencia'     => $eficiencia,
            'vs_anterior'    => $vsAnterior,
        ]);
    }

    // ── Helper ────────────────────────────────────────────────────────────────

    private function log($invId, $userId, $userName, $sucId, $sucNombre, $evento, $original, $nuevo, $comentario)
    {
        MermaAuditLog::create([
            'inventario_id'  => $invId,
            'usuario_id'     => $userId,
            'usuario_nombre' => $userName,
            'sucursal_id'    => $sucId,
            'sucursal_nombre'=> $sucNombre,
            'evento'         => $evento,
            'valor_original' => $original,
            'valor_nuevo'    => $nuevo,
            'comentario'     => $comentario,
        ]);
    }

    // Retorna true si no existe ningún inventario enviado/aprobado anterior para esta sucursal.
    private function esPrimerDia(int $sucursalId, string $fecha): bool
    {
        return !MermaInventario::where('sucursal_id', $sucursalId)
            ->where('fecha', '<', $fecha)
            ->whereIn('estado', ['enviado', 'aprobado'])
            ->exists();
    }

    private function resolverNombreUsuario(?int $usuarioId): string
    {
        if (!$usuarioId) return 'Bartender';
        $u = DB::connection('pgsql')->table('users')
            ->where('id', $usuarioId)
            ->value('name');
        return $u ?? 'Bartender';
    }
}
