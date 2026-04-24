<?php

namespace App\Http\Controllers\Api\RRHH\Traits;

use App\Http\Controllers\Traits\CapturesExceptions as BaseCapturesExceptions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Trait RRHHCapturesExceptions
 *
 * Versión RRHH del trait genérico CapturesExceptions.
 * Agrega al log contexto específico: empleado_id, departamento_codigo,
 * departamento_nombre del usuario autenticado (lookup en pgsql.empleados).
 *
 * Uso en controladores RRHH:
 *   use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;
 *
 *   public function store(Request $request): JsonResponse {
 *       return $this->captureAndRespond($request, function () use ($request) {
 *           // ... tu lógica
 *       });
 *   }
 */
trait RRHHCapturesExceptions
{
    use BaseCapturesExceptions;

    protected string $errorLogConnection = 'rrhh';
    protected string $errorLogSistema    = 'RRHH';

    /**
     * Contexto RRHH: empleado y departamento del usuario autenticado.
     */
    protected function buildErrorLogExtra(Request $request): array
    {
        $user = Auth::user();
        if (! $user) {
            return [];
        }

        try {
            $emp = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('departamentos as d', 'd.id', '=', 'e.departamento_id')
                ->where('e.user_id', $user->id)
                ->select('e.id as emp_id', 'd.codigo as dept_codigo', 'd.nombre as dept_nombre')
                ->first();

            return [
                'empleado_id'         => $emp?->emp_id,
                'departamento_codigo' => $emp?->dept_codigo,
                'departamento_nombre' => $emp?->dept_nombre,
            ];
        } catch (\Throwable) {
            return [];
        }
    }
}
