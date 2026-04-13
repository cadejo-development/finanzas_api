<?php

namespace App\Services\RRHH;

use Illuminate\Support\Facades\DB;

/**
 * Resolves the supervisor chain for HR actions based on the organigrama.
 *
 * Given an employee (subject) and an actor (who performs the action),
 * walks up the department tree to find the right supervisor to notify.
 *
 * Rules:
 * - Find the employee's department's jefe_empleado_id.
 * - If that jefe IS the actor, climb to the parent department (recursive).
 * - Return first supervisor who is NOT the actor.
 * - Returns null when at the root with no suitable supervisor.
 */
class SupervisorChainService
{
    /**
     * Find the supervisor to notify for a given action.
     *
     * @param  int  $empleadoId       The employee the action concerns
     * @param  int  $actorEmpleadoId  The employee performing the action
     * @return object|null            stdClass with { id, nombres, apellidos, email }
     */
    public function resolverSupervisorANotificar(int $empleadoId, int $actorEmpleadoId): ?object
    {
        // Find the employee's active department
        $dept = DB::connection('pgsql')
            ->table('departamentos as d')
            ->join('empleados as e', 'e.departamento_id', '=', 'd.id')
            ->where('e.id', $empleadoId)
            ->where('d.activo', true)
            ->select('d.id', 'd.nombre', 'd.parent_id', 'd.jefe_empleado_id')
            ->first();

        if (! $dept) {
            return null;
        }

        return $this->subirCadena($dept, $actorEmpleadoId, 0);
    }

    /**
     * Recursively climb the department tree until a non-actor supervisor is found.
     */
    private function subirCadena(object $dept, int $actorEmpleadoId, int $depth): ?object
    {
        // Guard against infinite recursion
        if ($depth > 15) {
            return null;
        }

        $jefeId = $dept->jefe_empleado_id ? (int) $dept->jefe_empleado_id : null;

        // No jefe in this dept → go to parent
        if (! $jefeId) {
            return $this->escalarPadre($dept, $actorEmpleadoId, $depth);
        }

        // The jefe IS the actor → go higher (actor is already aware)
        if ($jefeId === $actorEmpleadoId) {
            return $this->escalarPadre($dept, $actorEmpleadoId, $depth);
        }

        // Found the supervisor → return their data with email
        $supervisor = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.id', $jefeId)
            ->where('e.activo', true)
            ->whereNotNull('u.email')
            ->select('e.id', 'e.nombres', 'e.apellidos', 'u.email')
            ->first();

        return $supervisor;
    }

    /**
     * Climb to the parent department and continue the search.
     */
    private function escalarPadre(object $dept, int $actorEmpleadoId, int $depth): ?object
    {
        if (! $dept->parent_id) {
            return null;
        }

        $parentDept = DB::connection('pgsql')
            ->table('departamentos')
            ->where('id', $dept->parent_id)
            ->where('activo', true)
            ->select('id', 'nombre', 'parent_id', 'jefe_empleado_id')
            ->first();

        if (! $parentDept) {
            return null;
        }

        return $this->subirCadena($parentDept, $actorEmpleadoId, $depth + 1);
    }

    /**
     * Return the full display name of a supervisor object.
     */
    public static function nombreCompleto(object $supervisor): string
    {
        return trim(($supervisor->nombres ?? '') . ' ' . ($supervisor->apellidos ?? ''));
    }
}
