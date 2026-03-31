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
 *
 * Roles:
 *  - rrhh_admin : ve y gestiona todos los empleados (opcionalmente filtrado por
 *                 ?sucursal_id=N o ?departamento_id=N en el request).
 *  - jefatura   : ve y gestiona solo los empleados de su departamento/sucursal.
 */
abstract class RRHHBaseController extends Controller
{
    /**
     * Indica si el usuario autenticado tiene rol rrhh_admin.
     */
    protected function esAdminRrhh(): bool
    {
        return Auth::user()->hasRole('rrhh_admin');
    }

    /**
     * Retorna el registro Empleado del usuario autenticado.
     * Para rrhh_admin no es obligatorio tener empleado vinculado (retorna null).
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
     * Retorna los IDs del universo de empleados que puede gestionar el usuario:
     *  - rrhh_admin : todos los empleados activos (filtrable por sucursal_id/departamento_id del request).
     *  - jefatura   : solo los subordinados del departamento a cargo.
     */
    protected function getSubordinadosIds(): array
    {
        if ($this->esAdminRrhh()) {
            return $this->getTodosEmpleadosIds();
        }

        $user = Auth::user();

        $jefeEmpleadoId = DB::connection('pgsql')
            ->table('empleados')
            ->where('user_id', $user->id)
            ->value('id');

        if ($jefeEmpleadoId) {
            $deptId = DB::connection('pgsql')
                ->table('departamentos')
                ->where('jefe_empleado_id', $jefeEmpleadoId)
                ->where('activo', true)
                ->value('id');

            if ($deptId) {
                return DB::connection('pgsql')
                    ->table('empleados')
                    ->where('departamento_id', $deptId)
                    ->where('activo', true)
                    ->where('id', '!=', $jefeEmpleadoId)
                    ->pluck('id')
                    ->all();
            }

            // No department configured — only allow sucursal-wide scope for
            // restaurant branches (tipo = 'operativa'). Corporate branches
            // (area_corporativa) without a dept assignment see nobody.
            $sucursalTipo = DB::connection('pgsql')
                ->table('sucursales')
                ->where('id', $user->sucursal_id)
                ->value('tipo');

            if ($sucursalTipo === 'operativa') {
                return DB::connection('pgsql')
                    ->table('empleados')
                    ->where('sucursal_id', $user->sucursal_id)
                    ->where('activo', true)
                    ->pluck('id')
                    ->filter(fn($id) => $id !== $jefeEmpleadoId)
                    ->values()
                    ->all();
            }
        }

        // Corporate branch with no dept → jefe sees no subordinates
        return [];
    }

    /**
     * Retorna todos los empleados activos (uso exclusivo de rrhh_admin).
     * Acepta filtros opcionales del request: sucursal_id, departamento_id.
     */
    protected function getTodosEmpleadosIds(): array
    {
        $request = request();

        $query = DB::connection('pgsql')
            ->table('empleados')
            ->where('activo', true);

        if ($sucursalId = $request->input('sucursal_id')) {
            $query->where('sucursal_id', (int) $sucursalId);
        }

        if ($departamentoId = $request->input('departamento_id')) {
            $query->where('departamento_id', (int) $departamentoId);
        }

        return $query->pluck('id')->all();
    }

    /**
     * Verifica si el empleado_id es el mismo empleado del usuario autenticado.
     */
    protected function esEmpleadoPropio(int $empleadoId): bool
    {
        try {
            return $this->getJefeEmpleado()->id === $empleadoId;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Devuelve true si el usuario puede gestionar al empleado dado:
     *  - rrhh_admin: cualquier empleado activo.
     *  - jefatura: sus subordinados O su propio registro.
     */
    protected function puedeGestionar(int $empleadoId): bool
    {
        if ($this->esAdminRrhh()) {
            return DB::connection('pgsql')
                ->table('empleados')
                ->where('id', $empleadoId)
                ->where('activo', true)
                ->exists();
        }

        return $this->esEmpleadoPropio($empleadoId) || $this->esSubordinado($empleadoId);
    }

    /**
     * Devuelve el estado inicial para un registro según quién lo crea:
     *  - rrhh_admin o jefatura para subordinados → 'aprobado'
     *  - jefatura para sí mismo → 'pendiente'
     */
    protected function estadoParaEmpleado(int $empleadoId): string
    {
        if ($this->esAdminRrhh()) return 'aprobado';
        return $this->esEmpleadoPropio($empleadoId) ? 'pendiente' : 'aprobado';
    }

    /**
     * Verifica que el empleado_id pueda ser gestionado por el usuario actual.
     * Para rrhh_admin: cualquier empleado activo; para jefatura: solo subordinados.
     */
    protected function esSubordinado(int $empleadoId): bool
    {
        if ($this->esAdminRrhh()) {
            return DB::connection('pgsql')
                ->table('empleados')
                ->where('id', $empleadoId)
                ->where('activo', true)
                ->exists();
        }

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
