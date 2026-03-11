<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PortalController extends Controller
{
    /**
     * Devuelve los sistemas a los que el usuario autenticado tiene acceso
     * basándose en su role -> system_id en core_db.
     */
    public function sistemas(Request $request)
    {
        $user = $request->user();

        // portal_admin ve todos los sistemas sin filtro
        if ($user->hasRole('portal_admin')) {
            $sistemas = DB::table('systems')
                ->select('id', 'nombre', 'codigo', 'url', 'color', 'icon', 'descripcion')
                ->whereNotNull('url')
                ->orderBy('id')
                ->get();

            return response()->json(['sistemas' => $sistemas, 'is_portal_admin' => true]);
        }

        // Obtener todos los roles del usuario desde el pivot role_user
        $roles = DB::table('role_user')
            ->join('roles', 'roles.id', '=', 'role_user.role_id')
            ->where('role_user.user_id', $user->id)
            ->select('roles.system_id')
            ->get();

        $query = DB::table('systems')
            ->select('id', 'nombre', 'codigo', 'url', 'color', 'icon', 'descripcion')
            ->whereNotNull('url');

        if ($roles->isNotEmpty() && !$roles->contains('system_id', null)) {
            $systemIds = $roles->pluck('system_id')->unique()->values();
            $query->whereIn('id', $systemIds);
        }

        $sistemas = $query->orderBy('id')->get();

        return response()->json(['sistemas' => $sistemas, 'is_portal_admin' => false]);
    }
}
