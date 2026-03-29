<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CentroCosto;
use App\Models\System;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * POST /api/auth/login
     * Autentica al usuario y retorna token + info de roles/permisos para el sistema 'pagos'.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['Las credenciales proporcionadas son incorrectas.'],
            ]);
        }

        if (! $user->activo) {
            return response()->json([
                'success' => false,
                'message' => 'La cuenta está desactivada. Contacte al administrador.',
            ], 403);
        }

        // Cargar roles y permisos del sistema indicado (default: 'pagos')
        $sistemaCodigo = $request->input('sistema', 'pagos');

        // Revocar solo los tokens anteriores del mismo sistema para no invalidar otras sesiones activas
        $tokenName = $sistemaCodigo . '-token';
        $user->tokens()->where('name', $tokenName)->delete();

        $token = $user->createToken($tokenName)->plainTextToken;
        $sistema = System::where('codigo', $sistemaCodigo)->first();
        $roles = [];
        $permisos = [];

        if ($sistema) {
            $roles = $user->roles()
                ->where('system_id', $sistema->id)
                ->with('permissions')
                ->get()
                ->map(fn($r) => [
                    'id'     => $r->id,
                    'nombre' => $r->nombre,
                    'codigo' => $r->codigo,
                ]);

            $permisos = $user->roles()
                ->where('system_id', $sistema->id)
                ->with('permissions')
                ->get()
                ->flatMap(fn($r) => $r->permissions)
                ->unique('id')
                ->values()
                ->map(fn($p) => [
                    'id'     => $p->id,
                    'nombre' => $p->nombre,
                    'codigo' => $p->codigo,
                ]);
        }

        $centrosCosto = $this->centrosCostoDeUsuario($user->sucursal_id);
        $sucursalNombre = $user->sucursal_id
            ? Sucursal::find($user->sucursal_id)?->nombre
            : null;
        $todasSucursalesIds = $user->todasSucursalesIds();
        $todasSucursales    = Sucursal::whereIn('id', $todasSucursalesIds)->orderBy('nombre')->get()
            ->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre]);

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'              => $user->id,
                'name'            => $user->name,
                'email'           => $user->email,
                'activo'          => $user->activo,
                'sucursal_id'     => $user->sucursal_id,
                'sucursal'        => $sucursalNombre,
                'sucursales_ids'  => $todasSucursalesIds,
                'sucursales'      => $todasSucursales,
                'roles'           => $roles,
                'permisos'        => $permisos,
                'centros_costo'   => $centrosCosto,
                'is_portal_admin' => $user->hasRole('portal_admin'),
            ],
        ]);
    }

    /**
     * POST /api/auth/logout
     * Revoca el token actual.
     */
    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesión cerrada correctamente.',
        ]);
    }

    /**
     * GET /api/auth/me
     * Retorna el usuario autenticado con roles y permisos.
     */
    public function me(Request $request): JsonResponse
    {
        $user = $request->user();
        $sistemaCodigo = $request->query('sistema', 'pagos');
        $sistema = System::where('codigo', $sistemaCodigo)->first();

        $roles = [];
        $permisos = [];

        if ($sistema) {
            $roles = $user->roles()
                ->where('system_id', $sistema->id)
                ->with('permissions')
                ->get()
                ->map(fn($r) => [
                    'id'     => $r->id,
                    'nombre' => $r->nombre,
                    'codigo' => $r->codigo,
                ]);

            $permisos = $user->roles()
                ->where('system_id', $sistema->id)
                ->with('permissions')
                ->get()
                ->flatMap(fn($r) => $r->permissions)
                ->unique('id')
                ->values()
                ->map(fn($p) => [
                    'id'     => $p->id,
                    'nombre' => $p->nombre,
                    'codigo' => $p->codigo,
                ]);
        }

        $centrosCosto = $this->centrosCostoDeUsuario($user->sucursal_id);
        $sucursalNombre = $user->sucursal_id
            ? Sucursal::find($user->sucursal_id)?->nombre
            : null;
        $todasSucursalesIds = $user->todasSucursalesIds();
        $todasSucursales    = Sucursal::whereIn('id', $todasSucursalesIds)->orderBy('nombre')->get()
            ->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre]);

        return response()->json([
            'success' => true,
            'user'    => [
                'id'              => $user->id,
                'name'            => $user->name,
                'email'           => $user->email,
                'activo'          => $user->activo,
                'sucursal_id'     => $user->sucursal_id,
                'sucursal'        => $sucursalNombre,
                'sucursales_ids'  => $todasSucursalesIds,
                'sucursales'      => $todasSucursales,
                'roles'           => $roles,
                'permisos'        => $permisos,
                'centros_costo'   => $centrosCosto,
                'is_portal_admin' => $user->hasRole('portal_admin'),
            ],
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function centrosCostoDeUsuario(?int $sucursalId): array
    {
        if (! $sucursalId) {
            return [];
        }

        return CentroCosto::where('sucursal_id', $sucursalId)
            ->orderBy('nombre')
            ->get()
            ->map(fn ($cc) => [
                'codigo' => $cc->codigo,
                'nombre' => $cc->nombre,
            ])
            ->values()
            ->toArray();
    }
}
