<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    /** Todas las tablas de administración viven en core_db (conexión 'pgsql'). */
    private function db(): \Illuminate\Database\Connection
    {
        return DB::connection('pgsql');
    }

    // ──────────────────────────────────────────────────────────────
    // USUARIOS
    // ──────────────────────────────────────────────────────────────

    /**
     * GET /api/admin/usuarios
     * Lista empleados cruzados con users usando la FK directa empleados.user_id.
     */
    public function usuarios(Request $request): JsonResponse
    {
        $search = $request->query('search', '');

        $query = $this->db()->table('empleados as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.user_id')
            ->leftJoin('sucursales as s', 's.id', '=', 'e.sucursal_id')
            ->leftJoin('cargos as c', 'c.id', '=', 'e.cargo_id')
            ->select([
                'e.id as empleado_id',
                'e.codigo',
                'e.nombres',
                'e.apellidos',
                DB::raw("CONCAT(e.nombres, ' ', e.apellidos) as nombre_completo"),
                'e.email as empleado_email',
                'e.activo as empleado_activo',
                'e.cargo_id',
                'e.sucursal_id',
                's.nombre as sucursal',
                'c.nombre as cargo',
                'u.id as user_id',
                'u.email as user_email',
                'u.name as user_name',
                'u.activo as user_activo',
            ]);

        if ($search) {
            $words = array_values(array_filter(array_map('trim', explode(' ', $search))));
            $query->where(function ($q) use ($words) {
                foreach ($words as $word) {
                    $w = "%{$word}%";
                    $q->where(function ($sub) use ($w) {
                        $sub->whereRaw('e.nombres ILIKE ?', [$w])
                            ->orWhereRaw('e.apellidos ILIKE ?', [$w])
                            ->orWhereRaw('e.email ILIKE ?', [$w])
                            ->orWhereRaw('CAST(e.codigo AS TEXT) ILIKE ?', [$w])
                            ->orWhereRaw('c.nombre ILIKE ?', [$w])
                            ->orWhereRaw('s.nombre ILIKE ?', [$w]);
                    });
                }
            });
        }

        $empleados = $query->orderBy('e.apellidos')->paginate(25);

        // Decorar: tiene_usuario, roles del usuario
        $userIds = collect($empleados->items())->pluck('user_id')->filter()->values()->toArray();
        $rolesMap = [];
        if (!empty($userIds)) {
            $rows = $this->db()->table('role_user as ru')
                ->join('roles as r', 'r.id', '=', 'ru.role_id')
                ->join('systems as sys', 'sys.id', '=', 'r.system_id')
                ->whereIn('ru.user_id', $userIds)
                ->select('ru.user_id', 'r.id as role_id', 'r.nombre as role_nombre', 'r.codigo as role_codigo', 'sys.nombre as sistema')
                ->get();
            foreach ($rows as $row) {
                $rolesMap[$row->user_id][] = [
                    'id'     => $row->role_id,
                    'nombre' => $row->role_nombre,
                    'codigo' => $row->role_codigo,
                    'sistema'=> $row->sistema,
                ];
            }
        }

        $items = collect($empleados->items())->map(function ($e) use ($rolesMap) {
            return [
                'empleado_id'     => $e->empleado_id,
                'codigo'          => $e->codigo,
                'nombres'         => $e->nombres,
                'apellidos'       => $e->apellidos,
                'nombre_completo' => $e->nombre_completo,
                'email'           => $e->empleado_email,
                'cargo_id'        => $e->cargo_id,
                'sucursal_id'     => $e->sucursal_id,
                'sucursal'        => $e->sucursal,
                'cargo'           => $e->cargo,
                'empleado_activo' => $e->empleado_activo,
                'tiene_usuario'   => !is_null($e->user_id),
                'user'            => $e->user_id ? [
                    'id'     => $e->user_id,
                    'name'   => $e->user_name,
                    'email'  => $e->user_email,
                    'activo' => (bool) $e->user_activo,
                    'roles'  => $rolesMap[$e->user_id] ?? [],
                ] : null,
            ];
        });

        return response()->json([
            'data'         => $items,
            'total'        => $empleados->total(),
            'current_page' => $empleados->currentPage(),
            'last_page'    => $empleados->lastPage(),
            'con_usuario'  => $this->db()->table('empleados')->whereNotNull('user_id')->count(),
            'sin_usuario'  => $this->db()->table('empleados')->whereNull('user_id')->count(),
        ]);
    }

    /**
     * GET /api/admin/users-list  — lista simple de users (para select boxes)
     */
    public function usersList(): JsonResponse
    {
        $users = $this->db()->table('users as u')
            ->select('u.id', 'u.email', 'u.activo')
            ->orderBy('u.email')
            ->get();
        return response()->json(['users' => $users]);
    }

    /**
     * GET /api/admin/catalogos — sucursales, cargos y usuarios sin vincular
     */
    public function catalogos(): JsonResponse
    {
        // Usuarios que NO están vinculados a ningún empleado (disponibles para vincular)
        $linkedUserIds = $this->db()->table('empleados')->whereNotNull('user_id')->pluck('user_id');
        $usersLibres = $this->db()->table('users')
            ->whereNotIn('id', $linkedUserIds)
            ->select('id', 'name', 'email', 'activo')
            ->orderBy('email')
            ->get();

        return response()->json([
            'sucursales'   => $this->db()->table('sucursales')->where(fn($q) => $q->where('activa', true)->orWhereNull('activa'))->select('id', 'nombre')->orderBy('nombre')->get(),
            'cargos'       => $this->db()->table('cargos')->select('id', 'nombre')->orderBy('nombre')->get(),
            'users_libres' => $usersLibres,
        ]);
    }

    /**
     * PATCH /api/admin/empleados/{id}
     */
    public function updateEmpleado(Request $request, int $id): JsonResponse
    {
        abort_unless($this->db()->table('empleados')->where('id', $id)->exists(), 404);

        $data = $request->validate([
            'nombres'     => 'sometimes|required|string|max:100',
            'apellidos'   => 'sometimes|required|string|max:100',
            'email'       => 'sometimes|required|email|max:150',
            'cargo_id'    => 'sometimes|nullable|integer|exists:pgsql.cargos,id',
            'sucursal_id' => 'sometimes|nullable|integer|exists:pgsql.sucursales,id',
            'activo'      => 'sometimes|boolean',
        ]);

        $this->db()->table('empleados')->where('id', $id)->update($data);
        return response()->json(['message' => 'Empleado actualizado.']);
    }

    /**
     * PATCH /api/admin/users/{id}
     */
    public function updateUser(Request $request, int $id): JsonResponse
    {
        abort_unless($this->db()->table('users')->where('id', $id)->exists(), 404);

        $data = $request->validate([
            'name'        => 'sometimes|nullable|string|max:150',
            'sucursal_id' => 'sometimes|nullable|integer|exists:pgsql.sucursales,id',
            'email'       => "sometimes|required|email|max:150|unique:pgsql.users,email,{$id}",
        ]);

        if (isset($data['email'])) {
            $data['email'] = strtolower($data['email']);
        }

        $this->db()->table('users')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        return response()->json(['message' => 'Usuario actualizado.']);
    }

    /**
     * POST /api/admin/usuarios/{empleadoId}/crear-usuario
     * Crea un user para un empleado que aún no tiene.
     */
    public function crearUsuario(Request $request, int $empleadoId): JsonResponse
    {
        $empleado = $this->db()->table('empleados')->where('id', $empleadoId)->firstOrFail();

        $data = $request->validate([
            'password'   => 'required|string|min:8',
            'role_ids'   => 'nullable|array',
            'role_ids.*' => 'exists:pgsql.roles,id',
        ]);

        if ($this->db()->table('users')->whereRaw('LOWER(email) = LOWER(?)', [$empleado->email])->exists()) {
            return response()->json(['message' => 'Este empleado ya tiene usuario.'], 409);
        }

        $nombreCompleto = trim(($empleado->nombres ?? '') . ' ' . ($empleado->apellidos ?? ''));
        if (empty($nombreCompleto)) {
            $nombreCompleto = $empleado->email;
        }

        $userId = $this->db()->table('users')->insertGetId([
            'name'        => $nombreCompleto,
            'email'       => strtolower($empleado->email),
            'password'    => Hash::make($data['password']),
            'activo'      => true,
            'aud_usuario' => 'portal_admin',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);

        if (!empty($data['role_ids'])) {
            $now = now();
            $rows = array_map(fn($rId) => [
                'user_id'    => $userId,
                'role_id'    => $rId,
                'created_at' => $now,
                'updated_at' => $now,
            ], $data['role_ids']);
            $this->db()->table('role_user')->insert($rows);
        }

        // Vincular el empleado al nuevo usuario via FK directa
        $this->db()->table('empleados')->where('id', $empleadoId)->update(['user_id' => $userId]);

        return response()->json(['message' => 'Usuario creado correctamente.', 'user_id' => $userId], 201);
    }

    /**
     * POST /api/admin/empleados/{empleadoId}/vincular/{userId}
     * Vincula un usuario existente a un empleado (sin crear usuario nuevo).
     */
    public function vincularUsuario(int $empleadoId, int $userId): JsonResponse
    {
        $empleado = $this->db()->table('empleados')->where('id', $empleadoId)->first();
        abort_unless($empleado, 404);
        abort_unless($this->db()->table('users')->where('id', $userId)->exists(), 404);

        if ($empleado->user_id) {
            return response()->json(['message' => 'El empleado ya tiene un usuario vinculado.'], 409);
        }

        $yaVinculado = $this->db()->table('empleados')->where('user_id', $userId)->exists();
        if ($yaVinculado) {
            return response()->json(['message' => 'Este usuario ya está vinculado a otro empleado.'], 409);
        }

        $this->db()->table('empleados')->where('id', $empleadoId)->update(['user_id' => $userId]);
        return response()->json(['message' => 'Usuario vinculado correctamente.']);
    }

    /**
     * DELETE /api/admin/empleados/{empleadoId}/vincular
     * Desvincula el usuario del empleado (sin eliminar el usuario).
     */
    public function desvincularUsuario(int $empleadoId): JsonResponse
    {
        abort_unless($this->db()->table('empleados')->where('id', $empleadoId)->exists(), 404);
        $this->db()->table('empleados')->where('id', $empleadoId)->update(['user_id' => null]);
        return response()->json(['message' => 'Usuario desvinculado.']);
    }

    /**
     * PATCH /api/admin/users/{userId}/toggle
     * Activa / desactiva un usuario.
     */
    public function toggleUser(int $userId): JsonResponse
    {
        $user = $this->db()->table('users')->where('id', $userId)->first();
        abort_unless($user, 404);

        $nuevo = !$user->activo;
        $this->db()->table('users')->where('id', $userId)->update(['activo' => $nuevo, 'updated_at' => now()]);

        return response()->json(['activo' => $nuevo]);
    }

    /**
     * PATCH /api/admin/users/{userId}/password
     * Cambia la contraseña de un usuario.
     */
    public function cambiarPassword(Request $request, int $userId): JsonResponse
    {
        $request->validate(['password' => 'required|string|min:8']);
        abort_unless($this->db()->table('users')->where('id', $userId)->exists(), 404);

        $this->db()->table('users')->where('id', $userId)->update([
            'password'   => Hash::make($request->password),
            'updated_at' => now(),
        ]);

        return response()->json(['message' => 'Contraseña actualizada.']);
    }

    // ──────────────────────────────────────────────────────────────
    // ROLES
    // ──────────────────────────────────────────────────────────────

    /** GET /api/admin/roles */
    public function roles(): JsonResponse
    {
        $roles = $this->db()->table('roles as r')
            ->leftJoin('systems as s', 's.id', '=', 'r.system_id')
            ->select('r.id', 'r.nombre', 'r.codigo', 'r.is_active', 's.id as system_id', 's.nombre as sistema', 's.color')
            ->orderBy('s.nombre')->orderBy('r.nombre')
            ->get();

        return response()->json(['roles' => $roles]);
    }

    /** POST /api/admin/roles */
    public function storeRol(Request $request): JsonResponse
    {
        $data = $request->validate([
            'nombre'    => 'required|string|max:100',
            'codigo'    => ['required','string','max:80', Rule::unique('pgsql.roles','codigo')],
            'system_id' => 'required|exists:pgsql.systems,id',
        ]);

        $id = $this->db()->table('roles')->insertGetId(array_merge($data, [
            'is_active'  => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]));

        return response()->json(['id' => $id, 'message' => 'Rol creado.'], 201);
    }

    /** PATCH /api/admin/roles/{id} */
    public function updateRol(Request $request, int $id): JsonResponse
    {
        abort_unless($this->db()->table('roles')->where('id', $id)->exists(), 404);

        $data = $request->validate([
            'nombre'    => 'sometimes|required|string|max:100',
            'codigo'    => ['sometimes','required','string','max:80', Rule::unique('pgsql.roles','codigo')->ignore($id)],
            'system_id' => 'sometimes|required|exists:pgsql.systems,id',
            'is_active' => 'sometimes|boolean',
        ]);

        $this->db()->table('roles')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        return response()->json(['message' => 'Rol actualizado.']);
    }

    /** DELETE /api/admin/roles/{id} */
    public function deleteRol(int $id): JsonResponse
    {
        $inUse = $this->db()->table('role_user')->where('role_id', $id)->exists();
        if ($inUse) return response()->json(['message' => 'No se puede eliminar: el rol tiene usuarios asignados.'], 409);

        $this->db()->table('roles')->where('id', $id)->delete();
        return response()->json(['message' => 'Rol eliminado.']);
    }

    // ──────────────────────────────────────────────────────────────
    // ASIGNACIÓN ROLES ↔ USUARIOS
    // ──────────────────────────────────────────────────────────────

    /** GET /api/admin/users/{userId}/roles */
    public function rolesDeUsuario(int $userId): JsonResponse
    {
        abort_unless($this->db()->table('users')->where('id', $userId)->exists(), 404);

        $roles = $this->db()->table('role_user as ru')
            ->join('roles as r', 'r.id', '=', 'ru.role_id')
            ->leftJoin('systems as s', 's.id', '=', 'r.system_id')
            ->where('ru.user_id', $userId)
            ->select('r.id', 'r.nombre', 'r.codigo', 's.nombre as sistema', 's.color')
            ->get();

        return response()->json(['roles' => $roles]);
    }

    /** POST /api/admin/users/{userId}/roles/{roleId} */
    public function asignarRol(int $userId, int $roleId): JsonResponse
    {
        abort_unless($this->db()->table('users')->where('id', $userId)->exists(), 404);
        abort_unless($this->db()->table('roles')->where('id', $roleId)->exists(), 404);

        $exists = $this->db()->table('role_user')->where('user_id', $userId)->where('role_id', $roleId)->exists();
        if ($exists) return response()->json(['message' => 'El usuario ya tiene este rol.'], 409);

        $this->db()->table('role_user')->insert(['user_id' => $userId, 'role_id' => $roleId, 'created_at' => now(), 'updated_at' => now()]);
        return response()->json(['message' => 'Rol asignado.'], 201);
    }

    /** DELETE /api/admin/users/{userId}/roles/{roleId} */
    public function quitarRol(int $userId, int $roleId): JsonResponse
    {
        $this->db()->table('role_user')->where('user_id', $userId)->where('role_id', $roleId)->delete();
        return response()->json(['message' => 'Rol removido.']);
    }

    // ──────────────────────────────────────────────────────────────
    // SISTEMAS
    // ──────────────────────────────────────────────────────────────

    /** GET /api/admin/sistemas */
    public function sistemas(): JsonResponse
    {
        $sistemas = $this->db()->table('systems')
            ->select('id', 'nombre', 'codigo', 'url', 'color', 'icon', 'descripcion')
            ->orderBy('nombre')
            ->get();

        return response()->json(['sistemas' => $sistemas]);
    }

    /** PATCH /api/admin/sistemas/{id} */
    public function updateSistema(Request $request, int $id): JsonResponse
    {
        abort_unless($this->db()->table('systems')->where('id', $id)->exists(), 404);

        $data = $request->validate([
            'nombre'      => 'sometimes|required|string|max:100',
            'url'         => 'sometimes|nullable|string|max:255',
            'color'       => 'sometimes|required|string|max:10',
            'icon'        => 'sometimes|nullable|string',
            'descripcion' => 'sometimes|nullable|string|max:255',
        ]);

        $this->db()->table('systems')->where('id', $id)->update(array_merge($data, ['updated_at' => now()]));
        return response()->json(['message' => 'Sistema actualizado.']);
    }

    // ──────────────────────────────────────────────────────────────
    // SUCURSALES ADICIONALES DE USUARIO (multi-sucursal)
    // ──────────────────────────────────────────────────────────────

    /**
     * GET /api/admin/users/{userId}/sucursales
     * Devuelve las sucursales adicionales asignadas al usuario.
     */
    public function getSucursalesUsuario(int $userId): JsonResponse
    {
        abort_unless($this->db()->table('users')->where('id', $userId)->exists(), 404);

        $sucursales = $this->db()->table('user_sucursales as us')
            ->join('sucursales as s', 's.id', '=', 'us.sucursal_id')
            ->where('us.user_id', $userId)
            ->select('s.id', 's.nombre')
            ->orderBy('s.nombre')
            ->get();

        return response()->json(['sucursales' => $sucursales]);
    }

    /**
     * PUT /api/admin/users/{userId}/sucursales
     * Reemplaza la lista completa de sucursales adicionales del usuario.
     * Body: { "sucursal_ids": [1, 5, 6] }
     */
    public function setSucursalesUsuario(Request $request, int $userId): JsonResponse
    {
        abort_unless($this->db()->table('users')->where('id', $userId)->exists(), 404);

        $data = $request->validate([
            'sucursal_ids'   => 'present|array',
            'sucursal_ids.*' => 'integer|exists:pgsql.sucursales,id',
        ]);

        $ids = array_unique($data['sucursal_ids'] ?? []);

        $this->db()->table('user_sucursales')->where('user_id', $userId)->delete();

        if (!empty($ids)) {
            $rows = array_map(fn ($sid) => ['user_id' => $userId, 'sucursal_id' => $sid], $ids);
            $this->db()->table('user_sucursales')->insert($rows);
        }

        return response()->json(['message' => 'Sucursales actualizadas correctamente.', 'count' => count($ids)]);
    }
}
