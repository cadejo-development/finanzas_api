<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\Empleado;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Controlador base para el módulo RRHH.
 * Provee helpers para resolver el empleado del jefe autenticado
 * y obtener su listado de subordinados desde la DB core (pgsql).
 */
abstract class RRHHBaseController extends Controller
{
    /**
     * Retorna el registro Empleado del usuario autenticado.
     * Lanza una excepción HTTP 403 si el usuario no tiene empleado vinculado.
     */
    protected function getJefeEmpleado(): Empleado
    {
        $empleado = Empleado::where('user_id', Auth::id())->first();

        if (!$empleado) {
            abort(403, 'El usuario autenticado no tiene un empleado vinculado.');
        }

        return $empleado;
    }

    /**
     * Retorna los IDs de los empleados subordinados al jefe autenticado.
     * Criterio: empleados activos en el departamento donde el jefe está asignado,
     * con fallback a misma sucursal para retrocompatibilidad.
     */
    protected function getSubordinadosIds(): array
    {
        $user = Auth::user();

        // Buscar el registro de empleado del jefe autenticado
        $jefeEmpleadoId = DB::connection('pgsql')
            ->table('empleados')
            ->where('user_id', $user->id)
            ->value('id');

        if ($jefeEmpleadoId) {
            // Verificar si el jefe tiene un departamento asignado como jefe
            $deptId = DB::connection('pgsql')
                ->table('departamentos')
                ->where('jefe_empleado_id', $jefeEmpleadoId)
                ->where('activo', true)
                ->value('id');

            if ($deptId) {
                // Subordinados = empleados activos del departamento (excluyendo al jefe)
                return DB::connection('pgsql')
                    ->table('empleados')
                    ->where('departamento_id', $deptId)
                    ->where('activo', true)
                    ->where('id', '!=', $jefeEmpleadoId)
                    ->pluck('id')
                    ->all();
            }
        }

        // Fallback: misma sucursal (para retrocompatibilidad)
        return DB::connection('pgsql')
            ->table('empleados')
            ->where('sucursal_id', $user->sucursal_id)
            ->where('activo', true)
            ->pluck('id')
            ->filter(fn($id) => $id !== $jefeEmpleadoId)
            ->values()
            ->all();
    }

    /**
     * Verifica que el empleado_id pertenezca a los subordinados del jefe.
     */
    protected function esSubordinado(int $empleadoId): bool
    {
        return in_array($empleadoId, $this->getSubordinadosIds());
    }

    /**
     * Enriquece un array de registros con datos del empleado desde core.
     */
    protected function enrichWithEmpleadoData(array $records, string $empleadoIdKey = 'empleado_id'): array
    {
        $empleadoIds = collect($records)->pluck($empleadoIdKey)->unique()->filter()->all();

        if (empty($empleadoIds)) {
            return $records;
        }

        $empleados = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->join('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->whereIn('e.id', $empleadoIds)
            ->select('e.id', 'e.codigo', 'e.nombres', 'e.apellidos', 'c.nombre as cargo_nombre', 's.nombre as sucursal_nombre')
            ->get()
            ->keyBy('id');

        return collect($records)->map(function ($record) use ($empleados, $empleadoIdKey) {
            $arr = is_array($record) ? $record : (array) $record;
            $emp = $empleados[$arr[$empleadoIdKey] ?? null] ?? null;
            $arr['empleado_nombre']    = $emp ? trim($emp->nombres . ' ' . $emp->apellidos) : null;
            $arr['empleado_codigo']    = $emp?->codigo;
            $arr['cargo_nombre']       = $emp?->cargo_nombre;
            $arr['sucursal_nombre']    = $emp?->sucursal_nombre;
            return $arr;
        })->all();
    }
}
