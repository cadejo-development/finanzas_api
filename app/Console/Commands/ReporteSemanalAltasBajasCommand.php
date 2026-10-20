<?php

namespace App\Console\Commands;

use App\Mail\RRHH\ReporteSemanalAltasBajas;
use App\Models\RRHH\Desvinculacion;
use App\Models\RRHH\IngresoPersonal;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class ReporteSemanalAltasBajasCommand extends Command
{
    protected $signature = 'rrhh:reporte-semanal-altas-bajas
                            {--semana= : Fecha de inicio de semana YYYY-MM-DD (default: lunes anterior)}
                            {--dry-run : Solo muestra el resumen sin enviar correo}';

    protected $description = 'Envía a juanlopez@cervezacadejo.com el reporte semanal de altas y bajas';

    private const DESTINATARIO = 'juanlopez@cervezacadejo.com';

    public function handle(): int
    {
        $semanaInput = $this->option('semana');

        if ($semanaInput) {
            $inicio = \Carbon\Carbon::parse($semanaInput)->startOfDay();
        } else {
            // Lunes de la semana anterior
            $inicio = now()->startOfWeek(\Carbon\Carbon::MONDAY)->subWeek();
        }

        $fin        = $inicio->copy()->endOfWeek(\Carbon\Carbon::SUNDAY);
        $semanaLabel = $inicio->locale('es')->isoFormat('D MMM') . ' – ' . $fin->locale('es')->isoFormat('D MMM YYYY');

        $this->info("Generando reporte semanal de altas y bajas: {$semanaLabel}");

        // Altas: ingresos registrados en la semana
        $ingresosRaw = IngresoPersonal::whereBetween('fecha_ingreso', [$inicio->toDateString(), $fin->toDateString()])
            ->orderBy('fecha_ingreso')
            ->get();

        $altas = $ingresosRaw->map(fn ($i) => [
            'fecha'    => $i->fecha_ingreso->format('d/m/Y'),
            'empleado' => $i->empleado_nombre ?? "Empleado #{$i->empleado_id}",
            'cargo'    => $i->cargo_nombre    ?? '—',
            'sucursal' => $i->sucursal_nombre ?? '—',
        ])->all();

        // Bajas: desvinculaciones registradas en la semana
        $desvinRaw = Desvinculacion::whereBetween('fecha_efectiva', [$inicio->toDateString(), $fin->toDateString()])
            ->with('motivo')
            ->orderBy('fecha_efectiva')
            ->get();

        $tipoLabels = ['renuncia' => 'Renuncia', 'despido' => 'Despido', 'mutuo_acuerdo' => 'Mutuo acuerdo'];

        $bajas = $desvinRaw->map(fn ($d) => [
            'fecha'    => $d->fecha_efectiva->format('d/m/Y'),
            'empleado' => $d->empleado_nombre ?? "Empleado #{$d->empleado_id}",
            'cargo'    => $d->cargo_nombre    ?? '—',
            'sucursal' => $d->sucursal_nombre ?? '—',
            'tipo'     => $tipoLabels[$d->tipo] ?? ucfirst($d->tipo ?? '—'),
            'motivo'   => $d->motivo?->nombre  ?? '—',
        ])->all();

        $totalAltas = count($altas);
        $totalBajas = count($bajas);

        $this->info("Altas: {$totalAltas} | Bajas: {$totalBajas}");

        // Resumen por sucursal
        $altasPorSucursal = collect($altas)
            ->groupBy('sucursal')
            ->map(fn ($g, $k) => ['sucursal' => $k, 'total' => count($g)])
            ->sortByDesc('total')->values()->all();

        $bajasPorSucursal = collect($bajas)
            ->groupBy('sucursal')
            ->map(fn ($g, $k) => ['sucursal' => $k, 'total' => count($g)])
            ->sortByDesc('total')->values()->all();

        if ($this->option('dry-run')) {
            $this->table(['Sucursal', 'Altas'], $altasPorSucursal);
            $this->table(['Sucursal', 'Bajas'], $bajasPorSucursal);
            $this->info("Dry-run: correo no enviado.");
            return 0;
        }

        $mailable = new ReporteSemanalAltasBajas(
            semanaLabel:      $semanaLabel,
            totalAltas:       $totalAltas,
            totalBajas:       $totalBajas,
            altas:            $altas,
            bajas:            $bajas,
            altasPorSucursal: $altasPorSucursal,
            bajasPorSucursal: $bajasPorSucursal,
        );

        Mail::to(self::DESTINATARIO)->send($mailable);
        $this->info("Reporte enviado a " . self::DESTINATARIO);

        return 0;
    }
}
