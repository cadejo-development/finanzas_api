<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Finanzas\AprobacionController;
use App\Http\Controllers\Api\Finanzas\CatalogosFinanzasController;
use App\Http\Controllers\Api\Finanzas\DashboardSolicitudesPagoController;
use App\Http\Controllers\Api\Finanzas\SolicitudPagoController;
use App\Http\Controllers\Api\Finanzas\SolicitudPagoDetalleController;
use App\Http\Controllers\Api\Finanzas\SolicitudPagoAdjuntoController;
use App\Http\Controllers\Api\Finanzas\PresupuestoUnidadController;
use App\Http\Controllers\Api\Compras\VentasController;
use App\Http\Controllers\Api\Compras\ProductosController;
use App\Http\Controllers\Api\Compras\PedidosController;
use App\Http\Controllers\Api\Compras\RecetasController;

// ─── Autenticación (pública) ───────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('login', [AuthController::class, 'login']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
    });
});

// ─── Pagos (protegido con Sanctum) ────────────────────────────────────────
Route::prefix('pagos')->middleware('auth:sanctum')->group(function () {
    Route::post('solicitudes-pago/preview', [SolicitudPagoController::class, 'preview']);
    Route::get('dashboard-solicitudes-pago', [DashboardSolicitudesPagoController::class, 'resumen']);
    Route::get('contribuyentes', [CatalogosFinanzasController::class, 'contribuyentes']);
    Route::get('formas-pago', [CatalogosFinanzasController::class, 'formasPago']);
    Route::get('proveedores',  [CatalogosFinanzasController::class, 'proveedores']);
    Route::post('proveedores', [CatalogosFinanzasController::class, 'storeProveedor']);

    // Catálogos generales (sucursales, centros de costo, estados)
    Route::get('catalogos', [CatalogosFinanzasController::class, 'index']);

    // Solicitud de Pago
    Route::apiResource('solicitudes-pago', SolicitudPagoController::class);
    Route::apiResource('solicitudes-pago.detalles', SolicitudPagoDetalleController::class);
    Route::apiResource('solicitudes-pago.adjuntos', SolicitudPagoAdjuntoController::class);

    // Flujo de aprobación
    Route::post('solicitudes-pago/{id}/enviar',   [SolicitudPagoController::class, 'enviar']);
    Route::get('solicitudes-pago/{id}/aprobaciones', [AprobacionController::class, 'cadena']);
    Route::post('solicitudes-pago/{id}/aprobar',  [AprobacionController::class, 'aprobar']);
    Route::post('solicitudes-pago/{id}/rechazar', [AprobacionController::class, 'rechazar']);
    Route::post('solicitudes-pago/{id}/observar', [AprobacionController::class, 'observar']);
    Route::get('mis-aprobaciones',                [AprobacionController::class, 'misAprobaciones']);

    // Presupuesto Unidad
    Route::get('mi-presupuesto', [PresupuestoUnidadController::class, 'miPresupuesto']);
    Route::apiResource('presupuestos-unidad', PresupuestoUnidadController::class);
});

// ─── Compras (protegido con Sanctum) ──────────────────────────────────────
Route::prefix('compras')->middleware('auth:sanctum')->group(function () {
    // Catálogo de productos (paginado)
    Route::get('catalogos',           [ProductosController::class, 'catalogos']);
    Route::get('productos',           [ProductosController::class, 'index']);
    Route::get('sucursales',          [ProductosController::class, 'sucursales']);

    // Pedidos (bandeja y consolidado)
    Route::get('pedidos/semanas',     [PedidosController::class, 'semanas']);
    Route::get('pedidos/consolidado', [PedidosController::class, 'consolidado']);
    Route::get('pedidos/{id}',        [PedidosController::class, 'show']);
    Route::get('pedidos',             [PedidosController::class, 'index']);

    // Recetas (CRUD + calculo de ingredientes)
    Route::post('recetas/calcular',   [RecetasController::class, 'calcular']);
    Route::apiResource('recetas',     RecetasController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    // Ventas semanales
    Route::get('ventas',              [VentasController::class, 'index']);
    Route::get('ventas/sugerencia',   [VentasController::class, 'sugerencia']);
    Route::get('ventas/{id}',         [VentasController::class, 'show']);
    Route::post('ventas/import',      [VentasController::class, 'import']);
});
