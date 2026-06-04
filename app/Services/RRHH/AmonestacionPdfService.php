<?php

namespace App\Services\RRHH;

use App\Models\RRHH\Amonestacion;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AmonestacionPdfService
{
    public static function generar(Amonestacion $amonestacion): \Barryvdh\DomPDF\PDF
    {
        $amonestacion->loadMissing(['tipoFalta']);

        $emp = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c',      'c.id', '=', 'e.cargo_id')
            ->leftJoin('sucursales as s',   's.id', '=', 'e.sucursal_id')
            ->leftJoin('departamentos as d', 'd.id', '=', 'e.departamento_id')
            ->where('e.id', $amonestacion->empleado_id)
            ->select('e.nombres', 'e.apellidos', 'c.nombre as cargo', 's.nombre as sucursal', 'd.nombre as departamento')
            ->first();

        $jefe = $amonestacion->jefe_id
            ? DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('cargos as c', 'c.id', '=', 'e.cargo_id')
                ->where('e.id', $amonestacion->jefe_id)
                ->select('e.nombres', 'e.apellidos', 'c.nombre as cargo')
                ->first()
            : null;

        $gravedad  = $amonestacion->tipoFalta?->gravedad ?? 'leve';
        $tipoLabel = match ($gravedad) {
            'muy_grave' => 'MUY GRAVE',
            'grave'     => 'ESCRITA',
            default     => 'VERBAL',
        };

        $fechaCarbon = $amonestacion->fecha_amonestacion instanceof Carbon
            ? $amonestacion->fecha_amonestacion
            : Carbon::parse($amonestacion->fecha_amonestacion);

        $fecha = ucfirst($fechaCarbon->locale('es')->translatedFormat('l j \d\e F \d\e Y'));

        $data = [
            'tipoLabel'       => $tipoLabel,
            'tipoFaltaNombre' => $amonestacion->tipoFalta?->nombre ?? 'Falta',
            'fecha'           => $fecha,
            'empleadoNombre'  => $emp ? trim($emp->nombres . ' ' . $emp->apellidos) : '—',
            'cargoNombre'     => $emp?->cargo ?? '—',
            'unidad'          => $emp?->departamento ?? $emp?->sucursal ?? '—',
            'descripcion'     => $amonestacion->descripcion ?? '',
            'observacion'     => $amonestacion->observacion ?? '',
            'accionTomada'    => $amonestacion->accion_tomada ?? '',
            'jefeCargo'       => $jefe?->cargo ?? 'Jefe Inmediato',
        ];

        return Pdf::setOptions([
            'isRemoteEnabled' => true,
            'defaultFont'     => 'helvetica',
            'dpi'             => 96,
        ])->loadView('pdf.amonestacion', $data)->setPaper('letter', 'portrait');
    }

    public static function nombreArchivo(Amonestacion $amonestacion): string
    {
        return "Amonestacion_{$amonestacion->id}.pdf";
    }
}
