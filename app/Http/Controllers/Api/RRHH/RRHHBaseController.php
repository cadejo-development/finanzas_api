<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Mail\RRHH\AccionPersonalNotificacion;
use App\Mail\RRHH\NotificacionAlEmpleado;
use App\Mail\RRHH\SolicitudAprobacion;
use App\Models\Empleado;
use App\Services\RRHH\SupervisorChainService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;

/**
 * Controlador base para el módulo RRHH.
 * Provee helpers para resolver el empleado del jefe autenticado
 * y obtener su listado de subordinados desde la DB core (pgsql).
 *
 * Roles:
 *  - rrhh_admin : ve y gestiona todos los empleados (opcionalmente filtrado por
 *                 ?sucursal_id=N o ?departamento_id=N en el request).
 *  - jefatura   : ve y gestiona solo los empleados de su departamento/sucursal.
 */
abstract class RRHHBaseController extends Controller
{
    // ID del sistema RRHH en la tabla systems/roles
    private const RRHH_SYSTEM_ID = 5;

    /**
     * Indica si el usuario autenticado tiene rol rrhh_admin (scoped al sistema RRHH).
     */
    protected function esAdminRrhh(): bool
    {
        return Auth::user()->hasRole('rrhh_admin', self::RRHH_SYSTEM_ID);
    }

    /**
     * Indica si el usuario autenticado es empleado-solo en el sistema RRHH.
     * Solo chequea roles del sistema RRHH — roles de otros sistemas (portal_admin, etc.) son irrelevantes.
     */
    protected function esEmpleado(): bool
    {
        $user = Auth::user();
        return $user->hasRole('empleado', self::RRHH_SYSTEM_ID)
            && ! $user->hasRole('rrhh_admin', self::RRHH_SYSTEM_ID)
            && ! $user->hasRole('jefatura', self::RRHH_SYSTEM_ID);
    }

    /**
     * Retorna el registro Empleado del usuario autenticado.
     * Para rrhh_admin no es obligatorio tener empleado vinculado (retorna null).
     */
    protected function getJefeEmpleado(): Empleado
    {
        $empleado = Empleado::where('user_id', Auth::id())->first();

        if (!$empleado) {
            abort(403, 'El usuario autenticado no tiene un empleado vinculado.');
        }

        return $empleado;
    }

    /**
     * Retorna los IDs del universo de empleados que puede gestionar el usuario:
     *  - rrhh_admin : todos los empleados activos (filtrable por sucursal_id/departamento_id del request).
     *  - jefatura   : subordinados de TODOS los departamentos/sucursales a su cargo.
     *                 Prioridad: (1) departamentos donde es jefe_empleado_id,
     *                            (2) sucursales en empleado_jefaturas,
     *                            (3) sucursal propia del usuario (fallback legacy).
     */
    protected function getSubordinadosIds(): array
    {
        if ($this->esAdminRrhh()) {
            return $this->getTodosEmpleadosIds();
        }

        // Empleado con rol básico: solo ve sus propios registros
        if ($this->esEmpleado()) {
            $ownId = DB::connection('pgsql')
                ->table('empleados')
                ->where('user_id', Auth::id())
                ->value('id');
            return $ownId ? [(int) $ownId] : [];
        }

        $user = Auth::user();

        $jefeEmpleadoId = DB::connection('pgsql')
            ->table('empleados')
            ->where('user_id', $user->id)
            ->value('id');

        if (!$jefeEmpleadoId) return [];

        // ── 1. Todos los departamentos donde este empleado es jefe ────────────
        $deptIds = DB::connection('pgsql')
            ->table('departamentos')
            ->where('jefe_empleado_id', $jefeEmpleadoId)
            ->where('activo', true)
            ->pluck('id')
            ->all();

        if (!empty($deptIds)) {
            // ── Empleados directos del equipo (en los depts donde es jefe) ───────
            $directIds = DB::connection('pgsql')
                ->table('empleados')
                ->whereIn('departamento_id', $deptIds)
                ->where('activo', true)
                ->where('id', '!=', $jefeEmpleadoId)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            // ── Jefes de departamentos descendientes (CTE recursiva en pgsql) ───
            // Recorre el árbol de departamentos hijos y recoge sus jefe_empleado_id.
            // Esto permite que Rosa (Operaciones) gestione a los jefes de cada
            // restaurante hijo, y el jefe de Gerencia General gestione a Rosa, etc.
            $inPlaceholders = implode(',', array_fill(0, count($deptIds), '?'));
            $childJefes = DB::connection('pgsql')->select(<<<SQL
                WITH RECURSIVE sub_depts AS (
                    SELECT id, jefe_empleado_id
                    FROM departamentos
                    WHERE parent_id IN ({$inPlaceholders}) AND activo = true
                    UNION ALL
                    SELECT d.id, d.jefe_empleado_id
                    FROM departamentos d
                    INNER JOIN sub_depts s ON d.parent_id = s.id
                    WHERE d.activo = true
                )
                SELECT DISTINCT e.id
                FROM sub_depts s2
                INNER JOIN empleados e ON e.id = s2.jefe_empleado_id
                WHERE e.activo = true
                  AND e.id != ?
            SQL, array_merge($deptIds, [$jefeEmpleadoId]));

            $childJefeIds = collect($childJefes)
                ->pluck('id')
                ->map(fn($id) => (int) $id)
                ->toArray();

            return array_values(array_unique(array_merge($directIds, $childJefeIds)));
        }

        // ── 2. Sin departamentos: buscar sucursales asignadas en empleado_jefaturas ──
        $jefaturaSucursalIds = DB::connection('pgsql')
            ->table('empleado_jefaturas')
            ->where('empleado_id', $jefeEmpleadoId)
            ->where('activo', true)
            ->whereNotNull('sucursal_id')
            ->pluck('sucursal_id')
            ->all();

        if (!empty($jefaturaSucursalIds)) {
            // Solo sucursales operativas (no áreas corporativas)
            $operativaIds = DB::connection('pgsql')
                ->table('sucursales')
                ->whereIn('id', $jefaturaSucursalIds)
                ->where('tipo', 'operativa')
                ->pluck('id')
                ->all();

            if (!empty($operativaIds)) {
                return DB::connection('pgsql')
                    ->table('empleados')
                    ->whereIn('sucursal_id', $operativaIds)
                    ->where('activo', true)
                    ->where('id', '!=', $jefeEmpleadoId)
                    ->pluck('id')
                    ->all();
            }
        }

        // ── 3. Fallback legacy: sucursal propia del usuario ───────────────────
        $sucursalTipo = DB::connection('pgsql')
            ->table('sucursales')
            ->where('id', $user->sucursal_id)
            ->value('tipo');

        if ($sucursalTipo === 'operativa') {
            return DB::connection('pgsql')
                ->table('empleados')
                ->where('sucursal_id', $user->sucursal_id)
                ->where('activo', true)
                ->pluck('id')
                ->filter(fn($id) => $id !== $jefeEmpleadoId)
                ->values()
                ->all();
        }

        return [];
    }

    /**
     * Retorna todos los empleados activos (uso exclusivo de rrhh_admin).
     * Acepta filtros opcionales del request: sucursal_id, departamento_id.
     */
    protected function getTodosEmpleadosIds(): array
    {
        $request = request();

        $query = DB::connection('pgsql')
            ->table('empleados')
            ->where('activo', true);

        if ($sucursalId = $request->input('sucursal_id')) {
            $query->where('sucursal_id', (int) $sucursalId);
        }

        // El filtro por departamento se aplica en getEmpleadosFiltrados
        // via rrhh.departamento_empleado (tabla usada por el organigrama).
        // No filtrar aquí por pgsql.empleados.departamento_id para evitar
        // inconsistencias con la estructura del organigrama.

        return $query->pluck('id')->all();
    }

    /**
     * Verifica si el empleado_id es el mismo empleado del usuario autenticado.
     */
    protected function esEmpleadoPropio(int $empleadoId): bool
    {
        try {
            return $this->getJefeEmpleado()->id === $empleadoId;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Devuelve true si el usuario puede gestionar al empleado dado:
     *  - rrhh_admin: cualquier empleado activo.
     *  - jefatura: sus subordinados O su propio registro.
     */
    protected function puedeGestionar(int $empleadoId): bool
    {
        if ($this->esAdminRrhh()) {
            return DB::connection('pgsql')
                ->table('empleados')
                ->where('id', $empleadoId)
                ->where('activo', true)
                ->exists();
        }

        // Empleado solo puede gestionar su propio registro
        if ($this->esEmpleado()) {
            return $this->esEmpleadoPropio($empleadoId);
        }

        return $this->esEmpleadoPropio($empleadoId) || $this->esSubordinado($empleadoId);
    }

    /**
     * Devuelve el estado inicial para un registro según quién lo crea:
     *
     *  - rrhh_admin para un SUBORDINADO (no él mismo) → 'aprobado'  (el admin autoriza)
     *  - rrhh_admin para SÍ MISMO                    → 'pendiente' (sube al jefe del dept)
     *  - jefatura   para un SUBORDINADO               → 'aprobado'  (el jefe autoriza)
     *  - jefatura   para SÍ MISMO                    → 'pendiente' (sube al jefe del dept padre)
     *  - empleado   para sí mismo                    → 'pendiente'
     *
     * Guarda de seguridad: nunca auto-aprobar si jefe_id == empleado_id.
     */
    protected function estadoParaEmpleado(int $empleadoId, ?int $jefeId = null): string
    {
        if ($this->esAdminRrhh()) return 'aprobado';
        if ($this->esEmpleado()) return 'pendiente';
        $esPropio = $this->esEmpleadoPropio($empleadoId);
        if ($esPropio) return 'pendiente';
        if ($jefeId !== null && $jefeId === $empleadoId) return 'pendiente';
        return 'aprobado';
    }

    /**
     * Devuelve el ID del empleado que debe aprobar la solicitud de $empleadoId.
     *
     * Reglas:
     *  - Actor creando para un SUBORDINADO → él mismo aprueba (auto-aprobación).
     *  - Actor creando para SÍ MISMO       → jefe del departamento del empleado.
     *  - Si el empleado ES el jefe de su dept → jefe del departamento padre.
     *  - Fallback: null (sin aprobador identificado → quedará pendiente).
     */
    protected function getAprobadorPara(int $empleadoId): ?int
    {
        // Actor creando para un subordinado (no para sí mismo) → él mismo aprueba
        if (! $this->esEmpleadoPropio($empleadoId)) {
            try {
                return $this->getJefeEmpleado()->id;
            } catch (\Throwable) {
                // rrhh_admin sin empleado vinculado → buscar por jerarquía
            }
        }

        // Actor creando para sí mismo → buscar quién lo aprueba en la jerarquía
        return $this->getJefeDepartamento($empleadoId);
    }

    /**
     * Devuelve el jefe_empleado_id del departamento del empleado.
     * Si el empleado ya ES el jefe de su departamento, sube al padre.
     */
    protected function getJefeDepartamento(int $empleadoId): ?int
    {
        $row = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('departamentos as d', 'd.id', '=', 'e.departamento_id')
            ->where('e.id', $empleadoId)
            ->where('d.activo', true)
            ->select('d.id as dept_id', 'd.jefe_empleado_id', 'd.parent_id')
            ->first();

        if (! $row) return null;

        // Si el propio empleado es el jefe → subir al departamento padre
        if ((int) $row->jefe_empleado_id === $empleadoId) {
            if (! $row->parent_id) return null;

            $padre = DB::connection('pgsql')
                ->table('departamentos')
                ->where('id', $row->parent_id)
                ->where('activo', true)
                ->value('jefe_empleado_id');

            return $padre ? (int) $padre : null;
        }

        return (int) $row->jefe_empleado_id;
    }

    /**
     * Verifica que el empleado_id pueda ser gestionado por el usuario actual.
     * Para rrhh_admin: cualquier empleado activo; para jefatura: solo subordinados.
     */
    protected function esSubordinado(int $empleadoId): bool
    {
        if ($this->esAdminRrhh()) {
            return DB::connection('pgsql')
                ->table('empleados')
                ->where('id', $empleadoId)
                ->where('activo', true)
                ->exists();
        }

        return in_array($empleadoId, $this->getSubordinadosIds());
    }

    // ─── S3 helpers ───────────────────────────────────────────────────────────

    protected function s3Client(): \Aws\S3\S3Client
    {
        return new \Aws\S3\S3Client([
            'region'      => config('filesystems.disks.s3.region'),
            'version'     => 'latest',
            'credentials' => [
                'key'    => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
            'http' => ['timeout' => 5, 'connect_timeout' => 3],
        ]);
    }

    protected function s3TemporaryUrl(string $key, int $minutes = 60): string
    {
        $client = $this->s3Client();
        $cmd    = $client->getCommand('GetObject', [
            'Bucket' => config('filesystems.disks.s3.bucket'),
            'Key'    => $key,
        ]);
        return (string) $client->createPresignedRequest($cmd, "+{$minutes} minutes")->getUri();
    }

    /**
     * Enriquece un array de registros con datos del empleado desde core.
     */
    protected function enrichWithEmpleadoData(array $records, string $empleadoIdKey = 'empleado_id'): array
    {
        $empleadoIds = collect($records)->pluck($empleadoIdKey)->unique()->filter()->all();

        if (empty($empleadoIds)) {
            return $records;
        }

        $empleados = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->join('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->whereIn('e.id', $empleadoIds)
            ->select('e.id', 'e.codigo', 'e.nombres', 'e.apellidos', 'c.nombre as cargo_nombre', 's.nombre as sucursal_nombre')
            ->get()
            ->keyBy('id');

        return collect($records)->map(function ($record) use ($empleados, $empleadoIdKey) {
            $arr = is_array($record) ? $record : (array) $record;
            $emp = $empleados[$arr[$empleadoIdKey] ?? null] ?? null;
            $arr['empleado_nombre']    = $emp ? trim($emp->nombres . ' ' . $emp->apellidos) : null;
            $arr['empleado_codigo']    = $emp?->codigo;
            $arr['cargo_nombre']       = $emp?->cargo_nombre;
            $arr['sucursal_nombre']    = $emp?->sucursal_nombre;
            return $arr;
        })->all();
    }

    // ─── Notification helpers ─────────────────────────────────────────────────

    /**
     * Determines whether the actor's supervisor should be notified.
     * Notification is needed only when the actor is the subject of the action
     * (self-service). When a jefe acts on a subordinate, no email is sent
     * because the jefe is already aware.
     */
    protected function debeNotificar(int $empleadoId): bool
    {
        if ($this->esAdminRrhh()) return false;
        return $this->esEmpleadoPropio($empleadoId);
    }

    /**
     * Send a solicitud/approval-required email up the supervisor chain.
     * Used for actions that require supervisor approval (permisos, vacaciones).
     *
     * @param int    $empleadoId  The employee the action is about
     * @param string $tipo        Human-readable action type (e.g. 'Permiso')
     * @param array  $detalles    Key-value pairs to display in the email body
     * @param string $rutaFrontend  Frontend path segment (e.g. 'permisos')
     */
    protected function notificarSolicitud(
        int     $empleadoId,
        string  $tipo,
        array   $detalles,
        string  $rutaFrontend,
        ?int    $solicitudId  = null,
        ?string $tipoModelo   = null,
    ): void {
        $this->enviarNotificacion($empleadoId, $tipo, $detalles, $rutaFrontend, solicitud: true, solicitudId: $solicitudId, tipoModelo: $tipoModelo);
    }

    /**
     * Send an informational notification email up the supervisor chain.
     * Used for actions that do NOT require approval (incapacidades, etc.).
     */
    protected function notificarAccion(int $empleadoId, string $tipo, array $detalles, string $rutaFrontend): void
    {
        $this->enviarNotificacion($empleadoId, $tipo, $detalles, $rutaFrontend, solicitud: false);
    }

    /**
     * Internal: resolve supervisor chain and dispatch the appropriate email.
     */
    private function enviarNotificacion(
        int     $empleadoId,
        string  $tipo,
        array   $detalles,
        string  $rutaFrontend,
        bool    $solicitud,
        ?int    $solicitudId = null,
        ?string $tipoModelo  = null,
    ): void {
        $supervisor    = null;
        $mailable      = null;
        $supervisorEmail = null;

        try {
            $actorEmpleadoId = DB::connection('pgsql')
                ->table('empleados')
                ->where('user_id', Auth::id())
                ->value('id');

            if (! $actorEmpleadoId) return;

            $supervisor = (new SupervisorChainService)->resolverSupervisorANotificar(
                (int) $empleadoId,
                (int) $actorEmpleadoId,
            );

            if (! $supervisor || ! $supervisor->email) return;

            $supervisorEmail = $supervisor->email;

            $empleado = DB::connection('pgsql')
                ->table('empleados')
                ->where('id', $empleadoId)
                ->first();

            $empleadoNombre   = $empleado
                ? trim($empleado->nombres . ' ' . $empleado->apellidos)
                : "Empleado #{$empleadoId}";

            $supervisorNombre = SupervisorChainService::nombreCompleto($supervisor);
            $baseUrl          = rtrim(config('app.frontend_rrhh_url', 'https://rrhh.cervezacadejo.com'), '/');
            $linkUrl          = "{$baseUrl}/{$rutaFrontend}";

            $aprobarUrl  = null;
            $rechazarUrl = null;
            if ($solicitud && $solicitudId && $tipoModelo) {
                $aprobarUrl  = URL::temporarySignedRoute('rrhh.email.aprobar',  now()->addDays(5), ['tipo' => $tipoModelo, 'id' => $solicitudId]);
                $rechazarUrl = URL::temporarySignedRoute('rrhh.email.rechazar', now()->addDays(5), ['tipo' => $tipoModelo, 'id' => $solicitudId]);
            }

            $mailable = $solicitud
                ? new SolicitudAprobacion($tipo, $empleadoNombre, $supervisorNombre, $detalles, $linkUrl, $aprobarUrl, $rechazarUrl)
                : new AccionPersonalNotificacion($tipo, $empleadoNombre, $supervisorNombre, $detalles, $linkUrl);

            Mail::to($supervisorEmail)->send($mailable);

            $this->registrarEmailLog([
                'tipo'            => $solicitud ? 'solicitud_aprobacion' : 'accion_notificacion',
                'destinatario'    => $supervisorEmail,
                'asunto'          => $mailable->envelope()->subject,
                'estado'          => 'enviado',
                'enviado_por'     => Auth::user()->email,
                'referencia_id'   => $empleadoId,
                'referencia_tipo' => 'empleado',
            ]);

        } catch (\Throwable $e) {
            Log::warning('RRHH: Error enviando notificación por correo', [
                'empleado_id' => $empleadoId,
                'tipo'        => $tipo,
                'error'       => $e->getMessage(),
            ]);

            // Registrar el fallo en email_logs para auditoría
            $this->registrarEmailLog([
                'tipo'            => $solicitud ? 'solicitud_aprobacion' : 'accion_notificacion',
                'destinatario'    => $supervisorEmail ?? 'desconocido',
                'asunto'          => $mailable?->envelope()->subject ?? "Notificación {$tipo}",
                'estado'          => 'fallido',
                'error_mensaje'   => $e->getMessage(),
                'respuesta_api'   => json_encode([
                    'class'   => get_class($e),
                    'message' => $e->getMessage(),
                    'file'    => $e->getFile(),
                    'line'    => $e->getLine(),
                ]),
                'enviado_por'     => Auth::user()?->email,
                'referencia_id'   => $empleadoId,
                'referencia_tipo' => 'empleado',
            ]);
        }
    }

    /**
     * Envía un correo directamente al empleado afectado.
     * Usado para: amonestaciones, ausencias, etc.
     *
     * @param int    $empleadoId       ID del empleado que recibirá el correo
     * @param string $tipo             Tipo de acción (e.g. 'Amonestación')
     * @param string $mensaje          Texto introductorio del correo
     * @param array  $detalles         Key-value pairs para mostrar en el cuerpo
     * @param string $rutaFrontend     Path frontend (e.g. 'amonestaciones')
     */
    protected function notificarAlEmpleado(
        int    $empleadoId,
        string $tipo,
        string $mensaje,
        array  $detalles,
        string $rutaFrontend,
    ): void {
        $destinatarioEmail = null;
        $mailable          = null;

        try {
            $row = DB::connection('pgsql')
                ->table('empleados as e')
                ->join('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.id', $empleadoId)
                ->select('e.nombres', 'e.apellidos', 'u.email')
                ->first();

            if (! $row || ! $row->email) return;

            $destinatarioEmail  = $row->email;
            $empleadoNombre     = trim($row->nombres . ' ' . $row->apellidos);
            $baseUrl            = rtrim(config('app.frontend_rrhh_url', 'https://rrhh.cervezacadejo.com'), '/');

            $mailable = new NotificacionAlEmpleado(
                tipo:               $tipo,
                empleadoNombre:     $empleadoNombre,
                mensaje:            $mensaje,
                detalles:           $detalles,
                linkUrl:            "{$baseUrl}/{$rutaFrontend}",
                destinatarioNombre: $empleadoNombre,
            );

            Mail::to($destinatarioEmail)->send($mailable);

            $this->registrarEmailLog([
                'tipo'            => 'notificacion_empleado',
                'destinatario'    => $destinatarioEmail,
                'asunto'          => $mailable->envelope()->subject,
                'estado'          => 'enviado',
                'enviado_por'     => Auth::user()->email,
                'referencia_id'   => $empleadoId,
                'referencia_tipo' => 'empleado',
            ]);

        } catch (\Throwable $e) {
            Log::warning('RRHH: Error enviando notificación al empleado', [
                'empleado_id' => $empleadoId,
                'tipo'        => $tipo,
                'error'       => $e->getMessage(),
            ]);
            $this->registrarEmailLog([
                'tipo'            => 'notificacion_empleado',
                'destinatario'    => $destinatarioEmail ?? 'desconocido',
                'asunto'          => $mailable?->envelope()->subject ?? "Notificación {$tipo}",
                'estado'          => 'fallido',
                'error_mensaje'   => $e->getMessage(),
                'enviado_por'     => Auth::user()?->email,
                'referencia_id'   => $empleadoId,
                'referencia_tipo' => 'empleado',
            ]);
        }
    }

    /**
     * Envía un correo a todos los usuarios con rol rrhh_admin.
     * Usado para: desvinculaciones (despidos, renuncias).
     *
     * @param string $tipo            Tipo de acción (e.g. 'Desvinculación')
     * @param string $empleadoNombre  Nombre del empleado desvinculado
     * @param array  $detalles        Key-value pairs para mostrar en el cuerpo
     * @param string $rutaFrontend    Path frontend (e.g. 'desvinculaciones')
     */
    protected function notificarAdminsRrhh(
        string $tipo,
        string $empleadoNombre,
        array  $detalles,
        string $rutaFrontend,
    ): void {
        try {
            $admins = DB::connection('pgsql')
                ->table('model_has_roles as mhr')
                ->join('roles as r',  'r.id',  '=', 'mhr.role_id')
                ->join('users as u',  'u.id',  '=', 'mhr.model_id')
                ->where('r.name', 'rrhh_admin')
                ->where('mhr.model_type', 'App\\Models\\User')
                ->whereNotNull('u.email')
                ->select('u.id', 'u.name', 'u.email')
                ->get();

            if ($admins->isEmpty()) return;

            $baseUrl = rtrim(config('app.frontend_rrhh_url', 'https://rrhh.cervezacadejo.com'), '/');
            $linkUrl = "{$baseUrl}/{$rutaFrontend}";

            foreach ($admins as $admin) {
                $mailable = new NotificacionAlEmpleado(
                    tipo:               $tipo,
                    empleadoNombre:     $empleadoNombre,
                    mensaje:            "Se ha registrado una nueva {$tipo} para el siguiente colaborador. Este es un correo informativo dirigido al equipo de RRHH.",
                    detalles:           $detalles,
                    linkUrl:            $linkUrl,
                    destinatarioNombre: $admin->name,
                );

                Mail::to($admin->email)->send($mailable);

                $this->registrarEmailLog([
                    'tipo'            => 'notificacion_admins_rrhh',
                    'destinatario'    => $admin->email,
                    'asunto'          => $mailable->envelope()->subject,
                    'estado'          => 'enviado',
                    'enviado_por'     => Auth::user()->email,
                    'referencia_tipo' => 'empleado',
                ]);
            }

        } catch (\Throwable $e) {
            Log::warning('RRHH: Error notificando a admins RRHH', [
                'tipo'  => $tipo,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Envía un correo a todos los empleados activos del departamento con el código dado.
     * Usado para: traslados aprobados → notificar a Informática (GEN_INF).
     *
     * @param string $codigoDept       Código del departamento destino (e.g. 'GEN_INF')
     * @param string $tipo             Tipo de acción (e.g. 'Traslado Aprobado')
     * @param string $empleadoNombre   Nombre del empleado trasladado
     * @param array  $detalles         Key-value pairs para mostrar en el cuerpo
     * @param string $rutaFrontend     Path frontend (e.g. 'traslados')
     */
    protected function notificarDepartamentoCodigo(
        string $codigoDept,
        string $tipo,
        string $empleadoNombre,
        array  $detalles,
        string $rutaFrontend,
    ): void {
        try {
            $deptId = DB::connection('pgsql')
                ->table('departamentos')
                ->where('codigo', $codigoDept)
                ->where('activo', true)
                ->value('id');

            if (! $deptId) {
                Log::warning("RRHH: Departamento con código '{$codigoDept}' no encontrado para notificación.");
                return;
            }

            $miembros = DB::connection('pgsql')
                ->table('empleados as e')
                ->join('users as u', 'u.id', '=', 'e.user_id')
                ->where('e.departamento_id', $deptId)
                ->where('e.activo', true)
                ->whereNotNull('u.email')
                ->select('u.name', 'u.email')
                ->get();

            if ($miembros->isEmpty()) return;

            $baseUrl = rtrim(config('app.frontend_rrhh_url', 'https://rrhh.cervezacadejo.com'), '/');
            $linkUrl = "{$baseUrl}/{$rutaFrontend}";

            foreach ($miembros as $miembro) {
                $mailable = new NotificacionAlEmpleado(
                    tipo:               $tipo,
                    empleadoNombre:     $empleadoNombre,
                    mensaje:            "Se ha aprobado un traslado que requiere acciones de configuracion por parte del equipo de Informatica.",
                    detalles:           $detalles,
                    linkUrl:            $linkUrl,
                    destinatarioNombre: $miembro->name,
                );

                Mail::to($miembro->email)->send($mailable);

                $this->registrarEmailLog([
                    'tipo'            => 'notificacion_departamento',
                    'destinatario'    => $miembro->email,
                    'asunto'          => $mailable->envelope()->subject,
                    'estado'          => 'enviado',
                    'enviado_por'     => Auth::user()->email,
                    'referencia_tipo' => 'departamento',
                ]);
            }

        } catch (\Throwable $e) {
            Log::warning("RRHH: Error notificando al departamento '{$codigoDept}'", [
                'tipo'  => $tipo,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function registrarEmailLog(array $data): void
    {
        try {
            DB::connection('pgsql')->table('email_logs')->insert(array_merge(
                ['sistema' => 'rrhh', 'created_at' => now()],
                $data,
            ));
        } catch (\Throwable $e) {
            Log::error('RRHH: No se pudo registrar en email_logs', ['error' => $e->getMessage()]);
        }
    }
}
