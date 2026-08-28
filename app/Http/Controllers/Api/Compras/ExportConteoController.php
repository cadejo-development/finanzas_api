<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\BriloConfigSucursal;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ExportConteoController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/compras/export/conteo/estado?mes=7&year=2026
    // Estado de conteo/auditoría por sucursal para un mes
    // ──────────────────────────────────────────────────────────────────────────
    public function estado(Request $request)
    {
        $mes  = (int) $request->query('mes',  now()->month);
        $year = (int) $request->query('year', now()->year);

        $desde = Carbon::createFromDate($year, $mes, 1)->startOfMonth()->toDateString();
        $hasta = Carbon::createFromDate($year, $mes, 1)->endOfMonth()->toDateString();

        // Sucursales operativas (excluye tipo 'corporativo' si existe)
        $sucursales = DB::connection('pgsql')
            ->table('sucursales')
            ->select('id', 'nombre')
            ->where('activa', true)
            ->orderBy('nombre')
            ->get();

        // Conteos mensuales aplicados en este mes (una fila por sucursal)
        $conteos = DB::connection('compras')
            ->table('movimientos_inventario')
            ->select('sucursal_id', DB::raw('MAX(fecha) as fecha'))
            ->where('tipo', 'conteo_mensual')
            ->whereBetween('fecha', [$desde, $hasta])
            ->groupBy('sucursal_id')
            ->get()
            ->keyBy('sucursal_id');

        // Borradores activos del mes
        $borradores = DB::connection('compras')
            ->table('conteo_borradores')
            ->select('sucursal_id')
            ->where('estado', 'borrador')
            ->whereBetween('fecha_conteo', [$desde, $hasta])
            ->distinct()
            ->get()
            ->pluck('sucursal_id')
            ->flip(); // set para O(1) lookup

        // Auditorías del mes
        $auditorias = DB::connection('compras')
            ->table('conteo_auditorias')
            ->whereBetween('fecha_conteo', [$desde, $hasta])
            ->get()
            ->keyBy('sucursal_id');

        // Respuestas de gerencia
        $auditoriaIds = $auditorias->pluck('id')->filter()->values()->toArray();
        $respuestas   = $auditoriaIds
            ? DB::connection('compras')
                ->table('conteo_auditoria_respuestas')
                ->whereIn('auditoria_id', $auditoriaIds)
                ->orderBy('created_at', 'desc')
                ->get()
                ->keyBy('auditoria_id')
            : collect();

        // Configuración BOD por sucursal
        $configs = BriloConfigSucursal::all()->keyBy('sucursal_id');

        $resultado = [];
        foreach ($sucursales as $suc) {
            $aud  = $auditorias[$suc->id]  ?? null;
            $resp = $aud ? ($respuestas[$aud->id] ?? null) : null;
            $mov  = $conteos[$suc->id]    ?? null;
            $cfg  = $configs[$suc->id]    ?? null;

            // Determinar estado
            if ($aud && $aud->estado === 'cerrada' && $resp?->decision === 'aprobada') {
                $estado = 'aprobado';
            } elseif ($aud && $aud->estado === 'cerrada') {
                $estado = 'auditoria_cerrada';
            } elseif ($aud) {
                $estado = 'auditando';
            } elseif ($mov) {
                $estado = 'conteo_completo';
            } elseif (isset($borradores[$suc->id])) {
                $estado = 'contando';
            } else {
                $estado = 'sin_conteo';
            }

            $resultado[] = [
                'sucursal_id'    => $suc->id,
                'nombre'         => $suc->nombre,
                'estado'         => $estado,
                'fecha_conteo'   => $aud?->fecha_conteo ?? $mov?->fecha,
                'auditoria_id'   => $aud?->id,
                'bodega_codigo'  => $cfg?->bodega_codigo,
                'puede_exportar' => in_array($estado, ['auditoria_cerrada', 'aprobado']),
                'aprobado'       => $estado === 'aprobado',
            ];
        }

        return response()->json($resultado);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/compras/export/conteo/config
    // Devuelve configuración BOD de todas las sucursales
    // ──────────────────────────────────────────────────────────────────────────
    public function config()
    {
        return response()->json(BriloConfigSucursal::all()->keyBy('sucursal_id'));
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PUT /api/compras/export/conteo/config/{sucursal_id}
    // Guarda o actualiza el código de bodega de una sucursal
    // ──────────────────────────────────────────────────────────────────────────
    public function guardarConfig(Request $request, int $sucursalId)
    {
        $data = $request->validate([
            'bodega_codigo' => 'nullable|string|max:20',
        ]);

        $cfg = BriloConfigSucursal::firstOrNew(['sucursal_id' => $sucursalId]);
        $cfg->bodega_codigo = $data['bodega_codigo'] ?? null;
        $cfg->save();

        return response()->json($cfg);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/compras/export/conteo/csv/{auditoria_id}
    // Descarga el CSV de conteo para Brilo
    // ──────────────────────────────────────────────────────────────────────────
    public function generarCsv(int $auditoriaId)
    {
        $auditoria = DB::connection('compras')
            ->table('conteo_auditorias')
            ->where('id', $auditoriaId)
            ->first();

        if (!$auditoria) {
            return response()->json(['message' => 'Auditoría no encontrada'], 404);
        }
        if ($auditoria->estado !== 'cerrada') {
            return response()->json(['message' => 'Solo se puede exportar una auditoría cerrada'], 422);
        }

        // Items de la auditoría con código de producto
        $items = DB::connection('compras')
            ->table('conteo_auditoria_items as ai')
            ->join('productos as p', 'p.id', '=', 'ai.producto_id')
            ->select(
                'p.codigo',
                'ai.cantidad_contador',
                'ai.cantidad_auditor',
                'ai.unidad',
            )
            ->where('ai.auditoria_id', $auditoriaId)
            ->orderBy('p.codigo')
            ->get();

        // Config de bodega
        $cfg       = BriloConfigSucursal::find($auditoria->sucursal_id);
        $bodega    = $cfg?->bodega_codigo ?? '';

        // Nombre de archivo: CONTEO FISICO {SUCURSAL} {AÑO}M{MES}.csv
        $sucursal  = DB::connection('pgsql')
            ->table('sucursales')
            ->select('nombre')
            ->where('id', $auditoria->sucursal_id)
            ->first();

        $fecha     = Carbon::parse($auditoria->fecha_conteo);
        $mesStr    = $fecha->format('Y') . 'M' . str_pad($fecha->month, 2, '0', STR_PAD_LEFT);
        $sucNombre = strtoupper(trim($sucursal->nombre ?? 'SUCURSAL'));
        $filename  = "CONTEO FISICO {$sucNombre} {$mesStr}.csv";

        // Construir CSV
        $header = "Codigo Producto,Codigo Ubicacion,Posicion,Cantidad Contada,Codigo Empleado Contó,Fecha Hora Contado,# de Lote,# de Serie,Observaciones";
        $lines  = [$header];

        foreach ($items as $item) {
            // Auditor predomina sobre contador
            $cantidad = $item->cantidad_auditor ?? $item->cantidad_contador;
            // Redondear a máximo 4 decimales, quitar ceros innecesarios
            $cantidad = rtrim(number_format((float) $cantidad, 4, '.', ''), '0');
            $cantidad = rtrim($cantidad, '.');
            if ($cantidad === '' || $cantidad === '-') $cantidad = '0';

            $codigo = $item->codigo ?? '';
            $lines[] = "{$codigo},{$bodega},,{$cantidad},,,,";
        }

        $csv = implode("\n", $lines);

        return response($csv, 200, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Filename'          => $filename,
        ]);
    }
}
