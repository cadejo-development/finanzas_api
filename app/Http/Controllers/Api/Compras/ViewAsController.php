<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Sucursal;
use App\Models\System;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ViewAsController extends Controller
{
    /**
     * GET /compras/admin/view-as/{identifier}
     *
     * {identifier} puede ser:
     *   - username solo   : "marcelaorellana"
     *     → busca marcelaorellana@cervezacadejo.com
     *   - email completo  : "marcelaorellana@cervezacadejo.com"
     */
    public function lookup(Request $request, string $identifier): JsonResponse
    {
        if (str_contains($identifier, '@')) {
            $email = strtolower(trim($identifier));
        } else {
            $email = strtolower(trim($identifier)) . '@cervezacadejo.com';
        }

        $user = User::where('email', $email)->first();

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

        $sistema = System::where('codigo', 'compras')->first();
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

        return response()->json([
            'success' => true,
            'user'    => [
                'id'          => $user->id,
                'name'        => $user->name,
                'email'       => $user->email,
                'activo'      => $user->activo,
                'sucursal_id' => $user->sucursal_id,
                'sucursal'    => $sucursalNombre,
                'roles'       => $roles->values(),
                'permisos'    => $permisos->values(),
            ],
        ]);
    }
}
