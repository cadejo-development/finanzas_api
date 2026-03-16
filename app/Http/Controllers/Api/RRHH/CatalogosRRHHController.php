<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\MotivoDesvinculacion;
use App\Models\RRHH\TipoAumentoSalarial;
use App\Models\RRHH\TipoFalta;
use App\Models\RRHH\TipoIncapacidad;
use App\Models\RRHH\TipoPermiso;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CatalogosRRHHController extends RRHHBaseController
{
    /**
     * Catálogos generales + equipo a cargo del jefe.
     * GET /api/rrhh/catalogos
     */
    public function index(): JsonResponse
    {
        $user = Auth::user();

        // Empleados subordinados (misma sucursal, activos)
        $jefeEmpleadoId = DB::connection('pgsql')
            ->table('empleados')
            ->where('user_id', $user->id)
            ->value('id');

        $equipo = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->join('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->where('e.sucursal_id', $user->sucursal_id)
            ->where('e.activo', true)
            ->when($jefeEmpleadoId, fn($q) => $q->where('e.id', '!=', $jefeEmpleadoId))
            ->select('e.id', 'e.codigo', 'e.nombres', 'e.apellidos', 'e.fecha_ingreso', 'c.nombre as cargo', 's.nombre as sucursal')
            ->orderBy('e.apellidos')
            ->get();

        // Sucursales (para traslados)
        $sucursales = DB::connection('pgsql')
            ->table('sucursales')
            ->select('id', 'codigo', 'nombre')
            ->orderBy('nombre')
            ->get();

        // Cargos (para traslados)
        $cargos = DB::connection('pgsql')
            ->table('cargos')
            ->where('activo', true)
            ->select('id', 'codigo', 'nombre')
            ->orderBy('nombre')
            ->get();

        return response()->json([
            'success' => true,
            'data'    => [
                'tipos_permiso'          => TipoPermiso::where('activo', true)->get(),
                'tipos_incapacidad'      => TipoIncapacidad::where('activo', true)->get(),
                'tipos_falta'            => TipoFalta::where('activo', true)->get(),
                'motivos_desvinculacion' => MotivoDesvinculacion::where('activo', true)->get(),
                'tipos_aumento_salarial' => TipoAumentoSalarial::where('activo', true)->get(),
                'equipo'                 => $equipo,
                'sucursales'             => $sucursales,
                'cargos'                 => $cargos,
            ],
        ]);
    }
}
