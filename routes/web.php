<?php

use App\Http\Controllers\RRHH\SolicitudEmailController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// ── Acciones desde correo (signed URLs, sin auth) ─────────────────────────
Route::get('/rrhh/email/solicitud/{tipo}/{id}/aprobar', [SolicitudEmailController::class, 'aprobar'])
    ->name('rrhh.email.aprobar');

Route::get('/rrhh/email/solicitud/{tipo}/{id}/rechazar', [SolicitudEmailController::class, 'rechazar'])
    ->name('rrhh.email.rechazar');
