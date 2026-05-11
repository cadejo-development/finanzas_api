<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');

        $middleware->append(
            \Illuminate\Http\Middleware\HandleCors::class,
        );

        $middleware->alias([
            'role'       => \App\Http\Middleware\CheckRole::class,
            'permission' => \App\Http\Middleware\CheckPermission::class,
            'view-as'    => \App\Http\Middleware\ViewAsMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Garantizar headers CORS incluso en respuestas de error (500, 404, etc.)
        $exceptions->respond(function (Response $response) {
            $response->headers->set('Access-Control-Allow-Origin', '*');
            $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS');
            $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, X-Requested-With, X-View-As');
            return $response;
        });

        // Capturar excepciones HTTP 500 y escribirlas en error_logs (Monitor de Errores)
        $exceptions->report(function (\Throwable $e) {
            try {
                $request = request();
                // Solo rutas API de RRHH; ignorar errores de consola (artisan)
                if (! $request->is('api/*')) return false;

                $user  = \Illuminate\Support\Facades\Auth::user();
                $code  = method_exists($e, 'getStatusCode') ? $e->getStatusCode() : 500;
                if ($code < 500) return false; // solo 5xx

                \Illuminate\Support\Facades\DB::connection('rrhh')->table('error_logs')->insert([
                    'sistema'         => 'rrhh',
                    'controlador'     => get_class($e),
                    'funcion'         => $e->getFile() . ':' . $e->getLine(),
                    'metodo_http'     => $request->method(),
                    'url'             => $request->fullUrl(),
                    'tipo_excepcion'  => get_class($e),
                    'codigo_http'     => $code,
                    'mensaje'         => $e->getMessage(),
                    'trace'           => substr($e->getTraceAsString(), 0, 5000),
                    'request_data'    => json_encode($request->except(['password', 'token'])),
                    'ip'              => $request->ip(),
                    'user_agent'      => $request->userAgent(),
                    'usuario_email'   => $user?->email,
                    'usuario_id'      => $user?->id,
                    'severidad'       => 'error',
                    'resuelto'        => false,
                    'created_at'      => now(),
                ]);
            } catch (\Throwable) {
                // No fallar al fallar el log
            }
            return false; // deja que Laravel también lo loguee normalmente
        });
    })->create();
