<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use App\Models\System;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Endpoint para la funcionalidad "viewAs" (inspección de usuarios).
 *
 * Solo accesible por usuarios con rol rrhh_admin.
 * Retorna el perfil completo de un usuario para que el frontend pueda
 * impersonarlo en la UI (sin cambiar el token de sesión).
 */
class ViewAsController extends Controller
{
    /**
     * GET /rrhh/admin/view-as/{identifier}
     *
     * {identifier} puede ser:
     *   - Solo el username  : "marcelaorellana"
     *     → busca marcelaorellana@cervezacadejo.com
     *   - Email completo    : "marcelaorellana@cervezacadejo.com"
     */
    public function lookup(Request $request, string $identifier): JsonResponse
    {
        // Resolver email
        if (str_contains($identifier, '@')) {
            $email = strtolower(trim($identifier));
        } else {
            $email = strtolower(trim($identifier)) . '@cervezacadejo.com';
        }

        $user = User::where('email', $email)->first();

        // Fallback: búsqueda parcial por si el dominio es diferente
        if (! $user) {
            $user = User::where('email', 'LIKE', strtolower(trim($identifier)) . '@%')->first();
        }

        if (! $user) {
            return response()->json(['message' => 'Usuario no encontrado.'], 404);
        }

        if (! $user->activo) {
            return response()->json(['message' => 'El usuario está inactivo.'], 422);
        }

        if ($user->id === Auth::id()) {
            return response()->json(['message' => 'No puedes inspeccionar tu propio usuario.'], 422);
        }

        // Obtener roles y permisos para el sistema RRHH
        $sistema = System::where('codigo', 'rrhh')->first();
        $roles   = collect();
        $permisos = collect();

        if ($sistema) {
            $roles = $user->roles()
                ->where('system_id', $sistema->id)
                ->get()
                ->map(fn ($r) => [
                    'id'     => $r->id,
                    'nombre' => $r->nombre,
                    'codigo' => $r->codigo,
                ]);

            $permisos = $user->roles()
                ->where('system_id', $sistema->id)
                ->with('permissions')
                ->get()
                ->flatMap(fn ($r) => $r->permissions)
                ->unique('id')
                ->values()
                ->map(fn ($p) => [
                    'id'     => $p->id,
                    'nombre' => $p->nombre,
                    'codigo' => $p->codigo,
                ]);
        }

        $sucursalNombre = $user->sucursal_id
            ? Sucursal::find($user->sucursal_id)?->nombre
            : null;

        $empleadoId = DB::connection('pgsql')
            ->table('empleados')
            ->where('user_id', $user->id)
            ->value('id');

        return response()->json([
            'success' => true,
            'user'    => [
                'id'              => $user->id,
                'name'            => $user->name,
                'email'           => $user->email,
                'activo'          => $user->activo,
                'sucursal_id'     => $user->sucursal_id,
                'sucursal'        => $sucursalNombre,
                'sucursal_nombre' => $sucursalNombre,
                'nombre'          => $user->name,
                'roles'           => $roles->values(),
                'permisos'        => $permisos->values(),
                'empleado_id'     => $empleadoId,
            ],
        ]);
    }
}
