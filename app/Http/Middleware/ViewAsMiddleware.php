<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware para la funcionalidad "viewAs" (inspección de usuario).
 *
 * Si la petición incluye el header X-View-As con un user_id válido
 * y el usuario autenticado tiene rol rrhh_admin, se hace login temporal
 * como el usuario objetivo para que toda la lógica del request se ejecute
 * con el contexto de ese usuario.
 *
 * El token de autenticación NO cambia; solo se reemplaza Auth::user()
 * para la duración de ese request.
 */
class ViewAsMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $viewAsUserId = $request->header('X-View-As');

        if ($viewAsUserId && Auth::check()) {
            $realUser = Auth::user();

            $canViewAs = $realUser->hasRole('rrhh_admin')
                || $realUser->hasRole('admin_compras')
                || $realUser->hasRole('gerencia_financiera')
                || $realUser->hasRole('dir_comercial');

            if ($canViewAs) {
                $targetUser = \App\Models\User::find((int) $viewAsUserId);

                if ($targetUser) {
                    Auth::guard('sanctum')->setUser($targetUser);
                }
            }
        }

        return $next($request);
    }
}
