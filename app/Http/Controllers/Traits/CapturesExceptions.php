<?php

namespace App\Http\Controllers\Traits;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Trait CapturesExceptions  —  genérico / multi-sistema
 *
 * Úsalo en cualquier controlador de cualquier sistema para capturar
 * excepciones, persistirlas en la tabla `error_logs` del sistema
 * correspondiente y devolver una respuesta JSON limpia.
 *
 * ── CONFIGURACIÓN POR SISTEMA ──────────────────────────────────────────────
 *
 *   protected string $errorLogConnection = 'compras';   // conexión DB donde guardar
 *   protected string $errorLogSistema    = 'COMPRAS';   // etiqueta que aparece en el log
 *
 * ── CONTEXTO EXTRA (hook) ─────────────────────────────────────────────────
 *
 *   Cada sistema puede sobreescribir buildErrorLogExtra() para agregar
 *   datos específicos (ej. empleado_id, departamento_codigo en RRHH).
 *
 *   protected function buildErrorLogExtra(Request $request): array {
 *       return ['mi_campo' => 'valor'];
 *   }
 *
 * ── USO BÁSICO ────────────────────────────────────────────────────────────
 *
 *   public function store(Request $request): JsonResponse {
 *       return $this->captureAndRespond($request, function () use ($request) {
 *           // ... tu lógica aquí
 *           return response()->json(['success' => true]);
 *       });
 *   }
 */
trait CapturesExceptions
{
    // ── Propiedades a declarar por el trait de cada sistema ───────────────────
    // protected string $errorLogConnection = 'mi_conexion';
    // protected string $errorLogSistema    = 'MI_SISTEMA';
    // ─────────────────────────────────────────────────────────────────────────

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Envuelve la lógica del método controller capturando toda excepción.
     * Persiste el log en DB y devuelve JSON con el mensaje real del error.
     */
    protected function captureAndRespond(Request $request, callable $callback): JsonResponse
    {
        try {
            return $callback();

        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->persistLog($request, $e, 'warning', 422);
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'Error de validación.',
                'errors'  => $e->errors(),
            ], 422);

        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            $this->persistLog($request, $e, 'warning', $e->getStatusCode());
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Error HTTP ' . $e->getStatusCode(),
            ], $e->getStatusCode());

        } catch (Throwable $e) {
            $this->persistLog($request, $e, 'error', 500);
            Log::error(
                class_basename($this) . '@' . $this->resolveCallerFn() . ': ' . $e->getMessage(),
                ['user' => Auth::user()?->email, 'exception' => $e]
            );
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Hook: los controladores o traits derivados sobreescriben este método
     * para agregar campos específicos del sistema al log
     * (ej. empleado_id, departamento_codigo, sucursal, etc.).
     *
     * @return array  Pares clave => valor a mezclar en el registro del log.
     */
    protected function buildErrorLogExtra(Request $request): array
    {
        return [];
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    private function persistLog(Request $request, Throwable $e, string $severidad, int $codigoHttp): void
    {
        try {
            $user   = Auth::user();
            $caller = $this->resolveCallerFn();

            // Body de la request — elimina campos sensibles y objetos File
            $body = $request->except(['password', 'password_confirmation', 'token', 'secret']);
            foreach ($body as $key => $val) {
                if ($val instanceof \Illuminate\Http\UploadedFile) {
                    $body[$key] = '[archivo: ' . $val->getClientOriginalName()
                        . ' — ' . round($val->getSize() / 1024, 1) . ' KB]';
                }
            }

            // Contexto extra del sistema (hook)
            $extra = $this->buildErrorLogExtra($request);

            DB::connection($this->errorLogConnection)
                ->table('error_logs')
                ->insert(array_merge([
                    // ── Sistema ───────────────────────────────────────────
                    'sistema'            => $this->errorLogSistema,

                    // ── Origen del error ──────────────────────────────────
                    'controlador'        => class_basename($this),
                    'funcion'            => $caller,
                    'metodo_http'        => $request->method(),
                    'url'                => $request->fullUrl(),

                    // ── Excepción ─────────────────────────────────────────
                    'tipo_excepcion'     => get_class($e),
                    'codigo_http'        => (string) $codigoHttp,
                    'mensaje'            => $e->getMessage(),
                    'trace'              => $e->getTraceAsString(),

                    // ── Contexto de la request ─────────────────────────────
                    'request_data'       => json_encode($body),
                    'ip'                 => $request->ip(),
                    'user_agent'         => $request->userAgent(),

                    // ── Usuario autenticado ────────────────────────────────
                    'usuario_email'      => $user?->email,
                    'usuario_id'         => $user?->id,

                    // ── Clasificación ─────────────────────────────────────
                    'severidad'          => $severidad,
                    'resuelto'           => false,

                    'created_at'         => now(),
                    'updated_at'         => now(),
                ], $extra));

        } catch (Throwable $loggingError) {
            // Si persistir el log falla, al menos deja rastro en archivo
            Log::error('CapturesExceptions::persistLog falló — ' . $loggingError->getMessage());
        }
    }

    /**
     * Detecta el nombre del método del controlador que llamó a captureAndRespond.
     */
    private function resolveCallerFn(): string
    {
        $traces = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
        $skip   = ['captureAndRespond', 'persistLog', 'resolveCallerFn', 'buildErrorLogExtra'];

        foreach ($traces as $frame) {
            if (
                isset($frame['class'], $frame['function']) &&
                $frame['class'] === static::class &&
                ! in_array($frame['function'], $skip)
            ) {
                return $frame['function'];
            }
        }

        return 'unknown';
    }
}
