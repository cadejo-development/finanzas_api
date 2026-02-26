<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\Finanzas\CatalogosFinanzasController;
use App\Http\Controllers\Api\Finanzas\SolicitudPagoController;
use App\Http\Controllers\Api\Finanzas\SolicitudPagoDetalleController;
use App\Http\Controllers\Api\Finanzas\SolicitudPagoAdjuntoController;
use App\Http\Controllers\Api\Finanzas\PresupuestoUnidadController;

// Pagos: Catálogos y operaciones
Route::prefix('pagos')->group(function () {
    Route::post('solicitudes-pago/preview', [SolicitudPagoController::class, 'preview']);
    Route::get('contribuyentes', [CatalogosFinanzasController::class, 'contribuyentes']);
    Route::get('formas-pago', [CatalogosFinanzasController::class, 'formasPago']);
    Route::get('proveedores', [CatalogosFinanzasController::class, 'proveedores']);

    // Solicitud de Pago
    Route::apiResource('solicitudes-pago', SolicitudPagoController::class);
    Route::apiResource('solicitudes-pago.detalles', SolicitudPagoDetalleController::class);
    Route::apiResource('solicitudes-pago.adjuntos', SolicitudPagoAdjuntoController::class);

    // Presupuesto Unidad
    Route::apiResource('presupuestos-unidad', PresupuestoUnidadController::class);
});
