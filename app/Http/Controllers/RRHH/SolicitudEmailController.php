<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Vacacion;
use Illuminate\Http\Request;

class SolicitudEmailController extends Controller
{
    private const MODELOS = [
        'permiso'  => Permiso::class,
        'vacacion' => Vacacion::class,
    ];

    public function aprobar(Request $request, string $tipo, int $id)
    {
        return $this->procesarAccion($request, $tipo, $id, 'aprobado');
    }

    public function rechazar(Request $request, string $tipo, int $id)
    {
        return $this->procesarAccion($request, $tipo, $id, 'rechazado');
    }

    private function procesarAccion(Request $request, string $tipo, int $id, string $nuevoEstado)
    {
        if (! $request->hasValidSignature()) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => $nuevoEstado,
                'tipo'    => $tipo,
                'mensaje' => 'El enlace ha expirado o no es válido. Por favor gestiona la solicitud directamente desde el sistema.',
            ]);
        }

        $modelClass = self::MODELOS[$tipo] ?? null;

        if (! $modelClass) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => $nuevoEstado,
                'tipo'    => $tipo,
                'mensaje' => 'Tipo de solicitud no reconocido.',
            ]);
        }

        $solicitud = $modelClass::find($id);

        if (! $solicitud) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => $nuevoEstado,
                'tipo'    => $tipo,
                'mensaje' => 'La solicitud no fue encontrada en el sistema.',
            ]);
        }

        if ($solicitud->estado !== 'pendiente') {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => $solicitud->estado,
                'tipo'    => $tipo,
                'mensaje' => "Esta solicitud ya fue procesada anteriormente (estado: {$solicitud->estado}).",
            ]);
        }

        $solicitud->update(['estado' => $nuevoEstado]);

        return view('rrhh.resultado-solicitud', [
            'exito'   => true,
            'accion'  => $nuevoEstado,
            'tipo'    => $tipo,
            'mensaje' => $nuevoEstado === 'aprobado'
                ? 'La solicitud fue aprobada exitosamente.'
                : 'La solicitud fue rechazada.',
        ]);
    }
}
