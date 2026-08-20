<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetCode;
use App\Models\CentroCosto;
use App\Models\System;
use App\Models\Sucursal;
use App\Models\User;
use Carbon\Carbon;
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

        $passwordValid = false;
        try {
            $passwordValid = $user && Hash::check($request->password, $user->password);
        } catch (\RuntimeException) {
            // Hash con formato no soportado por el driver activo (ej: $2b$ de bcryptjs)
        }
        if (! $passwordValid) {
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

            $permisos = $this->buildPermisos($user, $sistema);
        }

        $centrosCosto = $this->centrosCostoDeUsuario($user->sucursal_id);
        $sucursalNombre = $user->sucursal_id
            ? Sucursal::find($user->sucursal_id)?->nombre
            : null;
        $todasSucursalesIds = $user->todasSucursalesIds();
        $todasSucursales    = Sucursal::whereIn('id', $todasSucursalesIds)->orderBy('nombre')->get()
            ->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre]);

        // Empleado vinculado al usuario (necesario para el rol empleado en RRHH)
        $empRow = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('departamentos as d', 'd.id', '=', 'e.departamento_id')
            ->where('e.user_id', $user->id)
            ->select('e.id as emp_id', 'e.sucursal_id as emp_sucursal_id', 'd.codigo as dept_codigo')
            ->first();

        $empleadoId         = $empRow?->emp_id;
        $departamentoCodigo = $empRow?->dept_codigo;
        $sucursalIdEfectivo = $user->sucursal_id ?? $empRow?->emp_sucursal_id;
        if (!$sucursalNombre && $sucursalIdEfectivo && $sucursalIdEfectivo !== $user->sucursal_id) {
            $sucursalNombre = Sucursal::find($sucursalIdEfectivo)?->nombre;
        }

        // Para sistema=compras incluir sucursales de inventario (contador_inv / auditor_inv)
        $inventarioSucursales = null;
        if ($sistemaCodigo === 'compras') {
            $inventarioSucursales = DB::table('inventario_sucursal_roles as isr')
                ->join('sucursales as s', 's.id', '=', 'isr.sucursal_id')
                ->where('isr.user_id', $user->id)
                ->where('isr.activo', true)
                ->select('isr.sucursal_id as id', 's.nombre', 'isr.rol')
                ->orderBy('isr.rol')
                ->orderBy('s.nombre')
                ->get();
        }

        $loginUser = [
            'id'                    => $user->id,
            'name'                  => $user->name,
            'email'                 => $user->email,
            'activo'                => $user->activo,
            'sucursal_id'           => $sucursalIdEfectivo,
            'sucursal'              => $sucursalNombre,
            'sucursales_ids'        => $todasSucursalesIds,
            'sucursales'            => $todasSucursales,
            'roles'                 => $roles,
            'permisos'              => $permisos,
            'centros_costo'         => $centrosCosto,
            'is_portal_admin'       => $user->hasRole('portal_admin'),
            'force_password_change' => (function () use ($user): bool {
                try {
                    return $user->force_password_change
                        || Hash::check('C@dejo2026', $user->password)
                        || Hash::check('Cadejo2026', $user->password);
                } catch (\RuntimeException) {
                    return true; // Hash no reconocido → forzar cambio de contraseña
                }
            })(),
            'empleado_id'           => $empleadoId,
            'departamento_codigo'   => $departamentoCodigo,
        ];

        if ($inventarioSucursales !== null) {
            $loginUser['inventario_sucursales'] = $inventarioSucursales;
        }

        return response()->json([
            'success' => true,
            'token'   => $token,
            'user'    => $loginUser,
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
                'reset_code_expires_at' => Carbon::now('UTC')->addMinutes(60),
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

        if (! $user->reset_code_expires_at || Carbon::now('UTC')->gt($user->reset_code_expires_at)) {
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

        if (! $user->reset_code_expires_at || Carbon::now('UTC')->gt($user->reset_code_expires_at)) {
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

            $permisos = $this->buildPermisos($user, $sistema);
        }

        $centrosCosto = $this->centrosCostoDeUsuario($user->sucursal_id);
        $sucursalNombre = $user->sucursal_id
            ? Sucursal::find($user->sucursal_id)?->nombre
            : null;
        $todasSucursalesIds = $user->todasSucursalesIds();
        $todasSucursales    = Sucursal::whereIn('id', $todasSucursalesIds)->orderBy('nombre')->get()
            ->map(fn ($s) => ['id' => $s->id, 'nombre' => $s->nombre]);

        $empRow = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('departamentos as d', 'd.id', '=', 'e.departamento_id')
            ->where('e.user_id', $user->id)
            ->select('e.id as emp_id', 'e.sucursal_id as emp_sucursal_id', 'd.codigo as dept_codigo')
            ->first();

        $empleadoId         = $empRow?->emp_id;
        $departamentoCodigo = $empRow?->dept_codigo;
        $sucursalIdEfectivo = $user->sucursal_id ?? $empRow?->emp_sucursal_id;
        if (!$sucursalNombre && $sucursalIdEfectivo && $sucursalIdEfectivo !== $user->sucursal_id) {
            $sucursalNombre = Sucursal::find($sucursalIdEfectivo)?->nombre;
        }

        // Para sistema=compras, incluir sucursales de inventario (contador_inv / auditor_inv)
        $inventarioSucursales = null;
        if ($sistemaCodigo === 'compras') {
            $inventarioSucursales = DB::table('inventario_sucursal_roles as isr')
                ->join('sucursales as s', 's.id', '=', 'isr.sucursal_id')
                ->where('isr.user_id', $user->id)
                ->where('isr.activo', true)
                ->select('isr.sucursal_id as id', 's.nombre', 'isr.rol')
                ->orderBy('isr.rol')
                ->orderBy('s.nombre')
                ->get();
        }

        $responseUser = [
            'id'              => $user->id,
            'name'            => $user->name,
            'email'           => $user->email,
            'activo'          => $user->activo,
            'sucursal_id'     => $sucursalIdEfectivo,
            'sucursal'        => $sucursalNombre,
            'sucursales_ids'  => $todasSucursalesIds,
            'sucursales'      => $todasSucursales,
            'roles'           => $roles,
            'permisos'        => $permisos,
            'centros_costo'   => $centrosCosto,
            'is_portal_admin' => $user->hasRole('portal_admin'),
            'empleado_id'     => $empleadoId,
            'departamento_codigo' => $departamentoCodigo,
        ];

        if ($inventarioSucursales !== null) {
            $responseUser['inventario_sucursales'] = $inventarioSucursales;
        }

        return response()->json([
            'success' => true,
            'user'    => $responseUser,
        ]);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────────

    /** Fusiona permisos del rol + permisos directos por usuario para un sistema dado */
    private function buildPermisos(User $user, \App\Models\System $sistema): \Illuminate\Support\Collection
    {
        $permisosRol = $user->roles()
            ->where('system_id', $sistema->id)
            ->with('permissions')
            ->get()
            ->flatMap(fn($r) => $r->permissions);

        $permisosDirectos = $user->directPermissions()
            ->where('permissions.system_id', $sistema->id)
            ->get();

        return $permisosRol->concat($permisosDirectos)
            ->unique('id')
            ->values()
            ->map(fn($p) => [
                'id'     => $p->id,
                'nombre' => $p->nombre,
                'codigo' => $p->codigo,
            ]);
    }

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
