<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetCode;
use App\Models\CentroCosto;
use App\Models\System;
use App\Models\Sucursal;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
                'id'                    => $user->id,
                'name'                  => $user->name,
                'email'                 => $user->email,
                'activo'                => $user->activo,
                'sucursal_id'           => $user->sucursal_id,
                'sucursal'              => $sucursalNombre,
                'sucursales_ids'        => $todasSucursalesIds,
                'sucursales'            => $todasSucursales,
                'roles'                 => $roles,
                'permisos'              => $permisos,
                'centros_costo'         => $centrosCosto,
                'is_portal_admin'       => $user->hasRole('portal_admin'),
                'force_password_change' => (bool) $user->force_password_change,
            ],
        ]);
    }

    // ─── Password reset / forced change ────────────────────────────────────────

    /**
     * POST /api/auth/password/request
     * Genera un código de 6 dígitos, lo guarda hasheado y envía el email.
     * Siempre responde con el mismo mensaje para no exponer si el email existe.
     */
    public function requestPasswordReset(Request $request): JsonResponse
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->where('activo', true)->first();

        if ($user) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);

            $user->update([
                'reset_code'            => $code,
                'reset_code_expires_at' => now()->addMinutes(15),
            ]);

            $estado       = 'enviado';
            $errorMensaje = null;
            $respuestaApi = null;

            try {
                Mail::to($user->email)->send(new PasswordResetCode($code, $user->name));
                $respuestaApi = 'OK';
            } catch (\Throwable $e) {
                $estado       = 'error';
                $errorMensaje = $e->getMessage();
                $respuestaApi = get_class($e);
            }

            try {
                DB::connection('pgsql')->table('email_logs')->insert([
                    'sistema'      => 'portal',
                    'tipo'         => 'password_reset',
                    'destinatario' => $user->email,
                    'asunto'       => 'Código de recuperación de contraseña — Cadejo Brewing Company',
                    'estado'       => $estado,
                    'error_mensaje'=> $errorMensaje,
                    'respuesta_api'=> $respuestaApi,
                    'enviado_por'  => 'sistema',
                    'referencia_id'=> $user->id,
                    'referencia_tipo' => 'user',
                    'created_at'   => now(),
                ]);
            } catch (\Throwable) {
                // El log no debe romper el flujo
            }
        }

        return response()->json([
            'message' => 'Si el correo está registrado, recibirás un código en tu bandeja de entrada.',
        ]);
    }

    /**
     * POST /api/auth/password/verify
     * Solo valida que el código sea correcto y no haya expirado (sin cambiar contraseña).
     */
    public function verifyResetCode(Request $request): JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code'  => 'required|string|size:6',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || $user->reset_code !== $request->code) {
            return response()->json(['message' => 'El código ingresado es inválido.'], 422);
        }

        if (! $user->reset_code_expires_at || now()->gt($user->reset_code_expires_at)) {
            return response()->json(['message' => 'El código ha expirado. Solicita uno nuevo.'], 422);
        }

        return response()->json(['success' => true]);
    }

    /**
     * POST /api/auth/password/reset
     * Valida el código y actualiza la contraseña.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email'                 => 'required|email',
            'code'                  => 'required|string|size:6',
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || $user->reset_code !== $request->code) {
            return response()->json(['message' => 'El código ingresado es inválido.'], 422);
        }

        if (! $user->reset_code_expires_at || now()->gt($user->reset_code_expires_at)) {
            return response()->json(['message' => 'El código ha expirado. Solicita uno nuevo.'], 422);
        }

        $user->update([
            'password'              => Hash::make($request->password),
            'reset_code'            => null,
            'reset_code_expires_at' => null,
            'force_password_change' => false,
        ]);

        // Revocar todos los tokens activos para cerrar todas las sesiones
        $user->tokens()->delete();

        return response()->json(['success' => true, 'message' => 'Contraseña actualizada. Ya puedes iniciar sesión.']);
    }

    /**
     * POST /api/auth/password/change  (requiere auth)
     * Cambio forzado de contraseña en el primer inicio de sesión.
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'password'              => 'required|string|min:8',
            'password_confirmation' => 'required|same:password',
        ]);

        $user = $request->user();

        $user->update([
            'password'              => Hash::make($request->password),
            'force_password_change' => false,
        ]);

        // Mantener solo el token actual, revocar el resto
        $currentId = $user->currentAccessToken()->id;
        $user->tokens()->where('id', '!=', $currentId)->delete();

        return response()->json(['success' => true, 'message' => 'Contraseña actualizada correctamente.']);
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
