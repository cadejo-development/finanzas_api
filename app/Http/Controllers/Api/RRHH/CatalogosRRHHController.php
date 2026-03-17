<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\MotivoDesvinculacion;
use App\Models\RRHH\TipoAumentoSalarial;
use App\Models\RRHH\TipoFalta;
use App\Models\RRHH\TipoIncapacidad;
use App\Models\RRHH\TipoPermiso;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class CatalogosRRHHController extends RRHHBaseController
{
    /**
     * Catálogos generales + equipo a cargo del jefe.
     * GET /api/rrhh/catalogos
     */
    public function index(): JsonResponse
    {
        // Obtener IDs del equipo completo (jefe + subordinados por departamento,
        // o fallback a sucursal si el usuario no tiene departamento asignado)
        $equipoIds = $this->getEquipoIds();

        $equipo = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->join('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->whereIn('e.id', $equipoIds)
            ->where('e.activo', true)
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
