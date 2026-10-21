<?php

namespace App\Console\Commands;

use App\Mail\RRHH\ReporteMensualAmonestaciones;
use App\Models\RRHH\Amonestacion;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class ReporteMensualAmonestacionesCommand extends Command
{
    protected $signature = 'rrhh:reporte-mensual-amonestaciones
                            {--mes= : Mes en formato YYYY-MM (default: mes anterior)}
                            {--email= : Destinatario alternativo (para pruebas)}
                            {--dry-run : Solo muestra el resumen sin enviar correo}';

    protected $description = 'Envía a juanlopez@cervezacadejo.com el reporte mensual de amonestaciones';

    private const DESTINATARIO = 'juanlopez@cervezacadejo.com';

    public function handle(): int
    {
        $mesInput = $this->option('mes');

        if ($mesInput) {
            $inicio = \Carbon\Carbon::createFromFormat('Y-m', $mesInput)->startOfMonth();
        } else {
            $inicio = now()->subMonth()->startOfMonth();
        }

        $fin      = $inicio->copy()->endOfMonth();
        $mesLabel = ucfirst($inicio->locale('es')->isoFormat('MMMM YYYY'));

        $this->info("Generando reporte de amonestaciones: {$mesLabel}");

        // Cargar amonestaciones del período
        $amonestaciones = Amonestacion::with('tipoFalta')
            ->whereBetween('fecha_amonestacion', [$inicio->toDateString(), $fin->toDateString()])
            ->orderBy('fecha_amonestacion')
            ->get();

        $total = $amonestaciones->count();
        $this->info("Total: {$total}");

        // Enriquecer con datos de empleados desde core_db
        $empleadoIds = $amonestaciones->pluck('empleado_id')->unique()->values()->all();
        $empleados   = [];

        if (!empty($empleadoIds)) {
            $rows = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('cargos as c',    'c.id', '=', 'e.cargo_id')
                ->leftJoin('sucursales as s', 's.id', '=', 'e.sucursal_id')
                ->whereIn('e.id', $empleadoIds)
                ->select('e.id', 'e.nombres', 'e.apellidos', 'c.nombre as cargo', 's.nombre as sucursal')
                ->get();

            foreach ($rows as $r) {
                $empleados[$r->id] = $r;
            }
        }

        // Armar detalle
        $detalle = [];
        foreach ($amonestaciones as $am) {
            $emp     = $empleados[$am->empleado_id] ?? null;
            $nombre  = $emp ? trim($emp->nombres . ' ' . $emp->apellidos) : "Empleado #{$am->empleado_id}";
            $detalle[] = [
                'fecha'      => $am->fecha_amonestacion->format('d/m/Y'),
                'empleado'   => $nombre,
                'sucursal'   => $emp?->sucursal ?? '—',
                'tipo_falta' => $am->tipoFalta?->nombre ?? '—',
                'sancion'    => $am->tipo_sancion ?? 'verbal',
            ];
        }

        // Resumen por sucursal
        $porSucursal = collect($detalle)
            ->groupBy('sucursal')
            ->map(fn ($g, $k) => ['sucursal' => $k, 'total' => count($g)])
            ->sortByDesc('total')
            ->values()
            ->all();

        // Resumen por tipo de sanción
        $porTipo = collect($detalle)
            ->groupBy('sancion')
            ->map(function ($g, $k) {
                $labels = ['verbal' => 'Verbal', 'escrita' => 'Escrita', 'suspension' => 'Suspensión'];
                return ['tipo' => $labels[$k] ?? ucfirst($k), 'total' => count($g)];
            })
            ->sortByDesc('total')
            ->values()
            ->all();

        if ($this->option('dry-run')) {
            $this->table(['Sanción', 'Total'], $porTipo);
            $this->table(['Sucursal', 'Total'], $porSucursal);
            $this->info("Dry-run: correo no enviado.");
            return 0;
        }

        $mailable = new ReporteMensualAmonestaciones(
            mesLabel:    $mesLabel,
            total:       $total,
            porSucursal: $porSucursal,
            porTipo:     $porTipo,
            detalle:     $detalle,
        );

        $destino = $this->option('email') ?: self::DESTINATARIO;
        Mail::to($destino)->send($mailable);
        $this->info("Reporte enviado a {$destino}");

        return 0;
    }
}
