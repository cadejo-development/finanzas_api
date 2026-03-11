<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Finanzas\AdminCoreController;
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
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\AdminController;

// ─── Portal SSO (protegido con Sanctum) ──────────────────────────────────
Route::prefix('portal')->middleware('auth:sanctum')->group(function () {
    Route::get('sistemas', [PortalController::class, 'sistemas']);
});

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

    // ── Administración Core (solo admin) ──────────────────────────────────
    Route::prefix('admin')->group(function () {
        Route::get('sucursales',           [AdminCoreController::class, 'sucursalesIndex']);
        Route::post('sucursales',          [AdminCoreController::class, 'sucursalesStore']);
        Route::put('sucursales/{id}',      [AdminCoreController::class, 'sucursalesUpdate']);
        Route::delete('sucursales/{id}',   [AdminCoreController::class, 'sucursalesDestroy']);

        Route::get('centros-costo',        [AdminCoreController::class, 'centrosCostoIndex']);
        Route::post('centros-costo',       [AdminCoreController::class, 'centrosCostoStore']);
        Route::put('centros-costo/{id}',   [AdminCoreController::class, 'centrosCostoUpdate']);
        Route::delete('centros-costo/{id}',[AdminCoreController::class, 'centrosCostoDestroy']);

        Route::get('cargos',               [AdminCoreController::class, 'cargosIndex']);
        Route::post('cargos',              [AdminCoreController::class, 'cargosStore']);
        Route::put('cargos/{id}',          [AdminCoreController::class, 'cargosUpdate']);
        Route::delete('cargos/{id}',       [AdminCoreController::class, 'cargosDestroy']);

        Route::get('empleados',            [AdminCoreController::class, 'empleadosIndex']);
        Route::post('empleados',           [AdminCoreController::class, 'empleadosStore']);
        Route::put('empleados/{id}',       [AdminCoreController::class, 'empleadosUpdate']);
        Route::delete('empleados/{id}',    [AdminCoreController::class, 'empleadosDestroy']);

        Route::get('jefaturas',            [AdminCoreController::class, 'jefaturasIndex']);
        Route::post('jefaturas',           [AdminCoreController::class, 'jefaturasStore']);
        Route::put('jefaturas/{id}',       [AdminCoreController::class, 'jefaturasUpdate']);
        Route::delete('jefaturas/{id}',    [AdminCoreController::class, 'jefaturasDestroy']);

        Route::get('tipos-jefatura',       [AdminCoreController::class, 'tiposJefaturaIndex']);
        Route::get('tipos-sucursal',       [AdminCoreController::class, 'tiposSucursalIndex']);
    });
});

// ─── Compras (protegido con Sanctum) ──────────────────────────────────────
Route::prefix('compras')->middleware('auth:sanctum')->group(function () {
    // Catálogo de productos (paginado)
    Route::get('catalogos',           [ProductosController::class, 'catalogos']);
    Route::get('productos',           [ProductosController::class, 'index']);
    Route::get('sucursales',          [ProductosController::class, 'sucursales']);

    // Pedidos (bandeja y consolidado)
    Route::get('pedidos/semanas',       [PedidosController::class, 'semanas']);
    Route::get('pedidos/consolidado',   [PedidosController::class, 'consolidado']);
    Route::get('pedidos/mi-borrador',   [PedidosController::class, 'miBorrador']);
    Route::put('pedidos/{id}/items',    [PedidosController::class, 'guardarItems']);
    Route::post('pedidos/{id}/enviar',  [PedidosController::class, 'enviar']);
    Route::get('pedidos/{id}',          [PedidosController::class, 'show']);
    Route::get('pedidos',               [PedidosController::class, 'index']);

    // Recetas (CRUD + calculo de ingredientes + platos por sucursal)
    Route::post('recetas/calcular',                    [RecetasController::class, 'calcular']);
    Route::patch('recetas/{id}/platos-sucursal',       [RecetasController::class, 'setPlatosSucursal']);
    Route::apiResource('recetas',     RecetasController::class)->only(['index', 'show', 'store', 'update', 'destroy']);

    // Ventas semanales
    Route::get('ventas',              [VentasController::class, 'index']);
    Route::get('ventas/sugerencia',   [VentasController::class, 'sugerencia']);
    Route::get('ventas/{id}',         [VentasController::class, 'show']);
    Route::post('ventas/import',      [VentasController::class, 'import']);
});

// ─── Admin Portal (protegido con Sanctum + portal_admin) ────────────────
Route::prefix('admin')->middleware('auth:sanctum')->group(function () {
    // Usuarios / Empleados
    Route::get('usuarios',                              [AdminController::class, 'usuarios']);
    Route::get('users-list',                            [AdminController::class, 'usersList']);
    Route::get('catalogos',                             [AdminController::class, 'catalogos']);
    Route::post('usuarios/{empleadoId}/crear-usuario',           [AdminController::class, 'crearUsuario']);
    Route::post('empleados/{empleadoId}/vincular/{userId}',      [AdminController::class, 'vincularUsuario']);
    Route::delete('empleados/{empleadoId}/vincular',             [AdminController::class, 'desvincularUsuario']);
    Route::patch('empleados/{id}',                      [AdminController::class, 'updateEmpleado']);
    Route::patch('users/{userId}/toggle',               [AdminController::class, 'toggleUser']);
    Route::patch('users/{userId}/password',             [AdminController::class, 'cambiarPassword']);
    Route::patch('users/{userId}',                      [AdminController::class, 'updateUser']);

    // Roles
    Route::get('roles',               [AdminController::class, 'roles']);
    Route::post('roles',              [AdminController::class, 'storeRol']);
    Route::patch('roles/{id}',        [AdminController::class, 'updateRol']);
    Route::delete('roles/{id}',       [AdminController::class, 'deleteRol']);

    // Asignación roles ↔ usuarios
    Route::get('users/{userId}/roles',              [AdminController::class, 'rolesDeUsuario']);
    Route::post('users/{userId}/roles/{roleId}',    [AdminController::class, 'asignarRol']);
    Route::delete('users/{userId}/roles/{roleId}',  [AdminController::class, 'quitarRol']);

    // Sistemas
    Route::get('sistemas',              [AdminController::class, 'sistemas']);
    Route::patch('sistemas/{id}',       [AdminController::class, 'updateSistema']);
});
