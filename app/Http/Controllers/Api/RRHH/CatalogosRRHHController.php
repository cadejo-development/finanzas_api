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
        $esAdmin = $this->esAdminRrhh();

        $jefeEmpleadoId = DB::connection('pgsql')
            ->table('empleados')
            ->where('user_id', $user->id)
            ->value('id');

        $equipoQuery = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->leftJoin('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->where('e.activo', true)
            ->select('e.id', 'e.codigo', 'e.nombres', 'e.apellidos', 'e.fecha_ingreso', 'e.sucursal_id', 'e.departamento_id', 'c.nombre as cargo', 's.nombre as sucursal')
            ->selectRaw('EXISTS(SELECT 1 FROM empleados e2 WHERE e2.jefe_id = e.id AND e2.activo = true) AS es_jefe');

        if ($esAdmin) {
            // Admin ve todos; opcionalmente filtrado por sucursal_id del request
            if ($sid = request()->input('sucursal_id')) {
                $equipoQuery->where('e.sucursal_id', (int) $sid);
            }
            if ($did = request()->input('departamento_id')) {
                $equipoQuery->where('e.departamento_id', (int) $did);
            }
        } else {
            // Jefatura ve sus subordinados + su propio registro
            $subordinadosIds = $this->getSubordinadosIds();
            $idsVisibles = $jefeEmpleadoId
                ? array_unique(array_merge([$jefeEmpleadoId], $subordinadosIds))
                : $subordinadosIds;

            if (empty($idsVisibles)) {
                $equipoQuery->whereRaw('1=0');
            } else {
                $equipoQuery->whereIn('e.id', $idsVisibles);
            }
        }

        $equipo = $equipoQuery->orderBy('e.apellidos')->get();

        // Fotos de perfil: una sola consulta a RRHH, luego presign local (sin red)
        $empleadoIds = $equipo->pluck('id')->all();
        $fotosMap = [];
        if (!empty($empleadoIds)) {
            $fotos = DB::connection('rrhh')
                ->table('expediente_archivos')
                ->where('tipo', 'foto_perfil')
                ->whereIn('empleado_id', $empleadoIds)
                ->orderByDesc('id')               // la más reciente
                ->select('empleado_id', 'archivo_ruta')
                ->get()
                ->unique('empleado_id');          // una por empleado
            foreach ($fotos as $f) {
                try {
                    $fotosMap[$f->empleado_id] = $this->s3TemporaryUrl($f->archivo_ruta, 480); // 8 horas
                } catch (\Throwable) {
                    // ignorar si falla el presign
                }
            }
        }

        // Adjuntar foto_url al equipo
        $equipoConFoto = $equipo->map(function ($e) use ($fotosMap) {
            $arr = (array) $e;
            $arr['foto_url'] = $fotosMap[$e->id] ?? null;
            return $arr;
        });

        // Sucursales (para traslados) — solo activas
        $sucursales = DB::connection('pgsql')
            ->table('sucursales')
            ->where('activa', true)
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

        // Departamentos (para admin: permite filtrar equipo por departamento)
        $departamentos = $esAdmin
            ? DB::connection('pgsql')
                ->table('departamentos')
                ->where('activo', true)
                ->select('id', 'nombre', 'sucursal_id', 'jefe_empleado_id')
                ->orderBy('nombre')
                ->get()
            : collect();

        return response()->json([
            'success' => true,
            'data'    => [
                'tipos_permiso'          => TipoPermiso::where('activo', true)->get(),
                'tipos_incapacidad'      => TipoIncapacidad::where('activo', true)->get(),
                'tipos_falta'            => TipoFalta::where('activo', true)->get(),
                'motivos_desvinculacion' => MotivoDesvinculacion::where('activo', true)->get(),
                'tipos_aumento_salarial' => TipoAumentoSalarial::where('activo', true)->get(),
                'equipo'                 => $equipoConFoto,
                'sucursales'             => $sucursales,
                'cargos'                 => $cargos,
                'departamentos'          => $departamentos,
                'es_admin'               => $esAdmin,
                'es_empleado'            => $this->esEmpleado(),
                'empleado_id_propio'     => $jefeEmpleadoId,
            ],
        ]);
    }
}
