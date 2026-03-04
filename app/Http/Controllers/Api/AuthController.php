<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\System;
use App\Models\User;
use App\Models\UserCentroCosto;
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

        // Revocar tokens anteriores del mismo dispositivo/nombre (opcional: purge all)
        $user->tokens()->where('name', 'api-token')->delete();

        $token = $user->createToken('api-token')->plainTextToken;

        // Cargar roles y permisos del sistema indicado (default: 'pagos')
        $sistemaCodigo = $request->input('sistema', 'pagos');
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

        $centrosCosto = $this->centrosCostoDeUsuario($user->id);

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'activo'        => $user->activo,
                'roles'         => $roles,
                'permisos'      => $permisos,
                'centros_costo' => $centrosCosto,
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

        $centrosCosto = $this->centrosCostoDeUsuario($user->id);

        return response()->json([
            'success' => true,
            'user'    => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'activo'        => $user->activo,
                'roles'         => $roles,
                'permisos'      => $permisos,
                'centros_costo' => $centrosCosto,
            ],
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    private function centrosCostoDeUsuario(int $userId): array
    {
        return UserCentroCosto::with('centroCosto')
            ->where('user_id', $userId)
            ->get()
            ->map(fn ($ucc) => [
                'codigo' => $ucc->centro_costo_codigo,
                'nombre' => $ucc->centroCosto?->nombre ?? $ucc->centro_costo_codigo,
            ])
            ->values()
            ->toArray();
    }
}
