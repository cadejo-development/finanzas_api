<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Mail\RRHH\AccionPersonalNotificacion;
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
    /**
     * Indica si el usuario autenticado tiene rol rrhh_admin.
     */
    protected function esAdminRrhh(): bool
    {
        return Auth::user()->hasRole('rrhh_admin');
    }

    /**
     * Indica si el usuario autenticado es empleado (solo rol empleado, sin roles de gestión).
     * Un empleado con rol adicional de jefatura/admin NO se considera solo empleado.
     */
    protected function esEmpleado(): bool
    {
        $user = Auth::user();
        return $user->hasRole('empleado')
            && ! $user->hasRole('rrhh_admin')
            && ! $user->hasRole('portal_admin')
            && ! $user->hasRole('jefatura');
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
            return DB::connection('pgsql')
                ->table('empleados')
                ->whereIn('departamento_id', $deptIds)
                ->where('activo', true)
                ->where('id', '!=', $jefeEmpleadoId)
                ->pluck('id')
                ->all();
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
     *  - rrhh_admin o jefatura para subordinados → 'aprobado'
     *  - jefatura para sí mismo → 'pendiente'
     */
    protected function estadoParaEmpleado(int $empleadoId): string
    {
        if ($this->esAdminRrhh()) return 'aprobado';
        // Empleado submitting own request always starts as pending
        if ($this->esEmpleado()) return 'pendiente';
        // Jefatura acting on subordinate → auto-approve; acting on self → pending
        return $this->esEmpleadoPropio($empleadoId) ? 'pendiente' : 'aprobado';
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
