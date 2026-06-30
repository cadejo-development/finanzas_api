<?php

namespace App\Http\Controllers\RRHH;

use App\Http\Controllers\Controller;
use App\Models\RRHH\Amonestacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ConfirmarAmonestacionController extends Controller
{
    /**
     * GET /rrhh/amonestacion/confirmar/{token}
     * Muestra la página de confirmación al empleado.
     */
    public function show(string $token)
    {
        $amonestacion = Amonestacion::with(['tipoFalta', 'diasSuspension'])
            ->where('token_aceptacion', $token)
            ->first();

        if (! $amonestacion) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => 'confirmar',
                'tipo'    => 'Amonestación',
                'mensaje' => 'El enlace no es válido o ya expiró. Contacta a tu jefe inmediato.',
            ]);
        }

        if ($amonestacion->acepta_empleado !== null) {
            $accionRealizada = $amonestacion->acepta_empleado ? 'aceptada' : 'rechazada';
            return view('rrhh.resultado-solicitud', [
                'exito'   => true,
                'accion'  => $accionRealizada,
                'tipo'    => 'Amonestación',
                'mensaje' => "Ya registraste tu respuesta ({$accionRealizada}) el " .
                             $amonestacion->fecha_aceptacion?->format('d/m/Y H:i') . '.',
            ]);
        }

        // Obtener datos del empleado
        $emp = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c',       'c.id', '=', 'e.cargo_id')
            ->leftJoin('sucursales as s',    's.id', '=', 'e.sucursal_id')
            ->where('e.id', $amonestacion->empleado_id)
            ->select('e.nombres', 'e.apellidos', 'c.nombre as cargo', 's.nombre as sucursal')
            ->first();

        return view('rrhh.confirmar-amonestacion', [
            'amonestacion' => $amonestacion,
            'token'        => $token,
            'emp'          => $emp,
        ]);
    }

    /**
     * POST /rrhh/amonestacion/confirmar/{token}
     * Guarda la respuesta del empleado (aceptar o rechazar).
     */
    public function confirmar(Request $request, string $token)
    {
        $amonestacion = Amonestacion::where('token_aceptacion', $token)->first();

        if (! $amonestacion) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => false,
                'accion'  => 'confirmar',
                'tipo'    => 'Amonestación',
                'mensaje' => 'El enlace no es válido o ya expiró.',
            ]);
        }

        if ($amonestacion->acepta_empleado !== null) {
            return view('rrhh.resultado-solicitud', [
                'exito'   => true,
                'accion'  => 'ya_respondido',
                'tipo'    => 'Amonestación',
                'mensaje' => 'Ya habías registrado tu respuesta anteriormente.',
            ]);
        }

        $request->validate([
            'decision'    => 'required|in:aceptar,rechazar',
            'comentario'  => 'nullable|string|max:1000',
        ]);

        $acepta = $request->input('decision') === 'aceptar';

        $amonestacion->update([
            'acepta_empleado'    => $acepta,
            'fecha_aceptacion'   => now(),
            'comentario_empleado'=> $request->input('comentario'),
        ]);

        return view('rrhh.resultado-solicitud', [
            'exito'   => true,
            'accion'  => $acepta ? 'aceptada' : 'rechazada',
            'tipo'    => 'Amonestación',
            'mensaje' => $acepta
                ? 'Has confirmado el recibo de la amonestación. Tu respuesta quedó registrada en el sistema.'
                : 'Has indicado que no estás de acuerdo. Tu comentario quedó registrado y será revisado por RRHH.',
        ]);
    }
}
