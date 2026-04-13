<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\Finanzas\AdminCoreController;
use App\Http\Controllers\Api\Finanzas\AprobacionController;
use App\Http\Controllers\Api\Finanzas\ReglaAprobacionController;
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
use App\Http\Controllers\Api\Compras\RecetaCategoriasController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\RRHH\CatalogosRRHHController;
use App\Http\Controllers\Api\RRHH\DashboardRRHHController;
use App\Http\Controllers\Api\RRHH\PermisosController;
use App\Http\Controllers\Api\RRHH\VacacionesController;
use App\Http\Controllers\Api\RRHH\IncapacidadesController;
use App\Http\Controllers\Api\RRHH\AmonestacionesController;
use App\Http\Controllers\Api\RRHH\DesvinculacionesController;
use App\Http\Controllers\Api\RRHH\TrasladosController;
use App\Http\Controllers\Api\RRHH\CambiosSalarialesController;
use App\Http\Controllers\Api\RRHH\DepartamentosController;
use App\Http\Controllers\Api\RRHH\ExpedienteController;
use App\Http\Controllers\Api\RRHH\CalendarioController;
use App\Http\Controllers\Api\RRHH\HistorialController;
use App\Http\Controllers\Api\RRHH\AusenciasController;
use App\Http\Controllers\Api\RRHH\ReportesRRHHController;
use App\Http\Controllers\Api\GeoController;

// ─── Geo catálogos El Salvador (público, sin auth) ────────────────────────
Route::prefix('geo')->group(function () {
    Route::get('departamentos',                    [GeoController::class, 'departamentos']);
    Route::get('departamentos/{id}/distritos',     [GeoController::class, 'distritos']);
    Route::get('distritos/{id}/municipios',        [GeoController::class, 'municipios']);
    Route::get('municipios/{id}/ubicacion',        [GeoController::class, 'ubicacionMunicipio']);
});

// ─── Portal SSO (protegido con Sanctum) ──────────────────────────────────
Route::prefix('portal')->middleware('auth:sanctum')->group(function () {
    Route::get('sistemas', [PortalController::class, 'sistemas']);
});

// ─── Health check (público, sin auth) ─────────────────────────────────────
Route::get('ping', function () {
    $checks = [];

    foreach (['pgsql' => 'core', 'compras' => 'compras', 'pagos' => 'pagos', 'rrhh' => 'rrhh'] as $connection => $label) {
        try {
            \Illuminate\Support\Facades\DB::connection($connection)->getPdo();
            $checks[$label] = 'ok';
        } catch (\Throwable $e) {
            $checks[$label] = 'error: ' . $e->getMessage();
        }
    }

    $allOk = collect($checks)->every(fn ($v) => $v === 'ok');

    return response()->json([
        'status'    => $allOk ? 'ok' : 'degraded',
        'timestamp' => now()->toIso8601String(),
        'databases' => $checks,
    ], $allOk ? 200 : 503);
});

// ─── Autenticación (pública) ───────────────────────────────────────────────
Route::prefix('auth')->group(function () {
    Route::post('login',             [AuthController::class, 'login']);
    Route::post('password/request',  [AuthController::class, 'requestPasswordReset']);
    Route::post('password/verify',   [AuthController::class, 'verifyResetCode']);
    Route::post('password/reset',    [AuthController::class, 'resetPassword']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::post('logout',           [AuthController::class, 'logout']);
        Route::get('me',                [AuthController::class, 'me']);
        Route::post('password/change',  [AuthController::class, 'changePassword']);
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

    // Subir adjunto (multipart)
    Route::post('solicitudes-pago/{solicitudId}/subir-adjunto', [SolicitudPagoAdjuntoController::class, 'subir']);

    // Flujo de aprobación
    Route::post('solicitudes-pago/{id}/enviar',        [SolicitudPagoController::class, 'enviar']);
    Route::post('solicitudes-pago/{id}/marcar-pagada', [SolicitudPagoController::class, 'marcarPagada']);
    Route::get('solicitudes-pago/{id}/aprobaciones',   [AprobacionController::class, 'cadena']);
    Route::post('solicitudes-pago/{id}/aprobar',       [AprobacionController::class, 'aprobar']);
    Route::post('solicitudes-pago/{id}/rechazar',      [AprobacionController::class, 'rechazar']);
    Route::post('solicitudes-pago/{id}/observar',      [AprobacionController::class, 'observar']);
    Route::get('mis-aprobaciones',                [AprobacionController::class, 'misAprobaciones']);
    Route::get('reglas-aprobacion',               [ReglaAprobacionController::class, 'index']);

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
    Route::get('catalogos',                      [ProductosController::class, 'catalogos']);
    Route::get('unidades',                       [ProductosController::class, 'unidades']);
    Route::get('sucursales',                     [ProductosController::class, 'sucursales']);
    Route::get('productos/siguiente-codigo',     [ProductosController::class, 'siguienteCodigo']);
    Route::get('productos',                      [ProductosController::class, 'index']);
    Route::post('productos',          [ProductosController::class, 'store']);
    Route::put('productos/{id}',      [ProductosController::class, 'update']);
    Route::delete('productos/{id}',   [ProductosController::class, 'destroy']);

    // Pedidos (bandeja y consolidado)
    Route::get('pedidos/semanas',       [PedidosController::class, 'semanas']);
    Route::get('pedidos/consolidado',   [PedidosController::class, 'consolidado']);
    Route::get('pedidos/exportar-odc',  [PedidosController::class, 'exportarOdc']);
    Route::get('pedidos/mi-borrador',   [PedidosController::class, 'miBorrador']);
    Route::put('pedidos/{id}/items',    [PedidosController::class, 'guardarItems']);
    Route::post('pedidos/{id}/enviar',  [PedidosController::class, 'enviar']);
    Route::get('pedidos/{id}',          [PedidosController::class, 'show']);
    Route::get('pedidos',               [PedidosController::class, 'index']);

    // Recetas (CRUD + calculo de ingredientes + platos por sucursal + upload fotos)
    Route::post('recetas/calcular',                    [RecetasController::class, 'calcular']);
    Route::get('recetas/dashboard',                    [RecetasController::class, 'dashboard']);
    Route::get('recetas/costos',                       [RecetasController::class, 'costos']);
    Route::get('recetas/tipos',                        [RecetasController::class, 'tipos']);
    Route::get('recetas/estados',                      [RecetasController::class, 'estados']);
    Route::post('recetas/{id}/pdf',                    [RecetasController::class, 'pdf']);
    Route::patch('recetas/{id}/platos-sucursal',       [RecetasController::class, 'setPlatosSucursal']);
    Route::get('upload/presign',                       [RecetasController::class, 'presignUpload']);
    Route::post('upload',                              [RecetasController::class, 'uploadFoto']);
    Route::apiResource('recetas',            RecetasController::class)->only(['index', 'show', 'store', 'update', 'destroy'])->whereNumber('receta');
    // Catálogo de categorías de recetas
    Route::apiResource('receta-categorias',  RecetaCategoriasController::class)->only(['index', 'store', 'update', 'destroy']);

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

    // Sucursales adicionales (multi-sucursal)
    Route::get('users/{userId}/sucursales',          [AdminController::class, 'getSucursalesUsuario']);
    Route::put('users/{userId}/sucursales',          [AdminController::class, 'setSucursalesUsuario']);

    // Sistemas
    Route::get('sistemas',              [AdminController::class, 'sistemas']);
    Route::patch('sistemas/{id}',       [AdminController::class, 'updateSistema']);
});

// ─── RRHH (protegido con Sanctum + rol jefatura o admin o empleado) ─────────
Route::prefix('rrhh')->middleware(['auth:sanctum', 'role:jefatura,portal_admin,rrhh_admin,empleado'])->group(function () {

    // Catálogos + equipo a cargo
    Route::get('catalogos', [CatalogosRRHHController::class, 'index']);

    // Dashboard KPIs
    Route::get('dashboard', [DashboardRRHHController::class, 'resumen']);

    // Calendario de equipo
    Route::get('calendario', [CalendarioController::class, 'index']);

    // Historial unificado
    Route::get('historial', [HistorialController::class, 'index']);

    // Permisos
    Route::get('permisos/saldos',   [PermisosController::class, 'saldos']);
    Route::get('permisos',          [PermisosController::class, 'index']);
    Route::post('permisos',         [PermisosController::class, 'store']);
    Route::get('permisos/{id}',     [PermisosController::class, 'show']);
    Route::put('permisos/{id}',     [PermisosController::class, 'update']);
    Route::delete('permisos/{id}',  [PermisosController::class, 'destroy']);

    // Vacaciones
    Route::get('vacaciones/saldos',     [VacacionesController::class, 'saldos']);
    Route::get('vacaciones',            [VacacionesController::class, 'index']);
    Route::post('vacaciones',           [VacacionesController::class, 'store']);
    Route::get('vacaciones/{id}',       [VacacionesController::class, 'show']);
    Route::put('vacaciones/{id}',       [VacacionesController::class, 'update']);
    Route::delete('vacaciones/{id}',    [VacacionesController::class, 'destroy']);

    // Incapacidades
    Route::get('incapacidades',                      [IncapacidadesController::class, 'index']);
    Route::post('incapacidades',                     [IncapacidadesController::class, 'store']);
    Route::get('incapacidades/{id}',                 [IncapacidadesController::class, 'show']);
    Route::put('incapacidades/{id}',                 [IncapacidadesController::class, 'update']);
    Route::delete('incapacidades/{id}',              [IncapacidadesController::class, 'destroy']);
    Route::patch('incapacidades/{id}/homologar',     [IncapacidadesController::class, 'homologar']);

    // Amonestaciones
    Route::get('amonestaciones',         [AmonestacionesController::class, 'index']);
    Route::post('amonestaciones',        [AmonestacionesController::class, 'store']);
    Route::get('amonestaciones/{id}',    [AmonestacionesController::class, 'show']);
    Route::put('amonestaciones/{id}',    [AmonestacionesController::class, 'update']);
    Route::delete('amonestaciones/{id}', [AmonestacionesController::class, 'destroy']);

    // Desvinculaciones (despidos + renuncias, filtrar por ?tipo=despido|renuncia)
    Route::get('desvinculaciones',         [DesvinculacionesController::class, 'index']);
    Route::post('desvinculaciones',        [DesvinculacionesController::class, 'store']);
    Route::get('desvinculaciones/{id}',    [DesvinculacionesController::class, 'show']);
    Route::put('desvinculaciones/{id}',    [DesvinculacionesController::class, 'update']);
    Route::delete('desvinculaciones/{id}', [DesvinculacionesController::class, 'destroy']);

    // Traslados
    Route::get('traslados',         [TrasladosController::class, 'index']);
    Route::post('traslados',        [TrasladosController::class, 'store']);
    Route::get('traslados/{id}',    [TrasladosController::class, 'show']);
    Route::put('traslados/{id}',    [TrasladosController::class, 'update']);
    Route::delete('traslados/{id}', [TrasladosController::class, 'destroy']);

    // Cambios salariales (aumentos + nivelaciones, filtrar por ?tipo_aumento_id=X)
    Route::get('cambios-salariales',         [CambiosSalarialesController::class, 'index']);
    Route::post('cambios-salariales',        [CambiosSalarialesController::class, 'store']);
    Route::get('cambios-salariales/{id}',    [CambiosSalarialesController::class, 'show']);
    Route::put('cambios-salariales/{id}',    [CambiosSalarialesController::class, 'update']);
    Route::delete('cambios-salariales/{id}', [CambiosSalarialesController::class, 'destroy']);

    // Ausencias Injustificadas
    Route::get('ausencias/resumen-mes',  [AusenciasController::class, 'resumenMes']);
    Route::get('ausencias',              [AusenciasController::class, 'index']);
    Route::post('ausencias',             [AusenciasController::class, 'store']);
    Route::delete('ausencias/{id}',      [AusenciasController::class, 'destroy']);

    // Reportes quincenales
    Route::get('reportes/quincena', [ReportesRRHHController::class, 'quincena']);
});

// ─── RRHH Expediente Digital (jefatura, rrhh_admin, portal_admin) ────────────
Route::prefix('rrhh/expediente')->middleware(['auth:sanctum', 'role:jefatura,portal_admin,rrhh_admin'])->group(function () {
    // Cabecera + secciones
    Route::get('{empleadoId}',                           [ExpedienteController::class, 'show']);
    Route::get('{empleadoId}/acciones',                  [ExpedienteController::class, 'acciones']);

    // Datos personales (upsert)
    Route::put('{empleadoId}/datos-personales',          [ExpedienteController::class, 'upsertDatosPersonales']);

    // Contactos
    Route::post('{empleadoId}/contactos',                [ExpedienteController::class, 'storeContacto']);
    Route::put('{empleadoId}/contactos/{contactoId}',    [ExpedienteController::class, 'updateContacto']);
    Route::delete('{empleadoId}/contactos/{contactoId}', [ExpedienteController::class, 'destroyContacto']);

    // Direcciones
    Route::post('{empleadoId}/direcciones',              [ExpedienteController::class, 'storeDireccion']);
    Route::put('{empleadoId}/direcciones/{dirId}',       [ExpedienteController::class, 'updateDireccion']);
    Route::delete('{empleadoId}/direcciones/{dirId}',    [ExpedienteController::class, 'destroyDireccion']);

    // Documentos
    Route::post('{empleadoId}/documentos',               [ExpedienteController::class, 'storeDocumento']);
    Route::put('{empleadoId}/documentos/{docId}',        [ExpedienteController::class, 'updateDocumento']);
    Route::delete('{empleadoId}/documentos/{docId}',     [ExpedienteController::class, 'destroyDocumento']);

    // Estudios
    Route::post('{empleadoId}/estudios',                              [ExpedienteController::class, 'storeEstudio']);
    Route::put('{empleadoId}/estudios/{estudioId}',                   [ExpedienteController::class, 'updateEstudio']);
    Route::delete('{empleadoId}/estudios/{estudioId}',                [ExpedienteController::class, 'destroyEstudio']);
    Route::get('{empleadoId}/estudios/{estudioId}/atestado/presign',  [ExpedienteController::class, 'presignAtestadoEstudio']);
    Route::post('{empleadoId}/estudios/{estudioId}/atestado',         [ExpedienteController::class, 'subirAtestadoEstudio']);
    Route::get('{empleadoId}/estudios/{estudioId}/atestado',          [ExpedienteController::class, 'verAtestadoEstudio']);

    // Archivos
    Route::get('{empleadoId}/archivos/presign',                       [ExpedienteController::class, 'presignArchivo']);
    Route::post('{empleadoId}/archivos',                              [ExpedienteController::class, 'uploadArchivo']);
    Route::get('{empleadoId}/archivos/{archivoId}/descargar',         [ExpedienteController::class, 'descargarArchivo']);
    Route::delete('{empleadoId}/archivos/{archivoId}',                [ExpedienteController::class, 'destroyArchivo']);

    // Fotos de documentos (frente / reverso)
    Route::get('{empleadoId}/documentos/{docId}/foto/{campo}/presign',    [ExpedienteController::class, 'presignFotoDocumento']);
    Route::patch('{empleadoId}/documentos/{docId}/foto/{campo}',          [ExpedienteController::class, 'subirFotoDocumento']);
    Route::get('{empleadoId}/documentos/{docId}/foto/{campo}',            [ExpedienteController::class, 'verFotoDocumento']);

    // Idiomas
    Route::post('{empleadoId}/idiomas',                           [ExpedienteController::class, 'storeIdioma']);
    Route::put('{empleadoId}/idiomas/{idiomaId}',                 [ExpedienteController::class, 'updateIdioma']);
    Route::delete('{empleadoId}/idiomas/{idiomaId}',              [ExpedienteController::class, 'destroyIdioma']);
    Route::post('{empleadoId}/idiomas/{idiomaId}/atestado',       [ExpedienteController::class, 'subirAtestadoIdioma']);
    Route::get('{empleadoId}/idiomas/{idiomaId}/atestado/presign',[ExpedienteController::class, 'presignAtestadoIdioma']);
    Route::get('{empleadoId}/idiomas/{idiomaId}/atestado',        [ExpedienteController::class, 'verAtestadoIdioma']);

    // Experiencia Laboral
    Route::post('{empleadoId}/experiencia',                       [ExpedienteController::class, 'storeExperiencia']);
    Route::put('{empleadoId}/experiencia/{expId}',                [ExpedienteController::class, 'updateExperiencia']);
    Route::delete('{empleadoId}/experiencia/{expId}',             [ExpedienteController::class, 'destroyExperiencia']);

    // Cuentas Bancarias
    Route::post('{empleadoId}/cuentas-banco',                     [ExpedienteController::class, 'storeCuentaBanco']);
    Route::put('{empleadoId}/cuentas-banco/{cuentaId}',           [ExpedienteController::class, 'updateCuentaBanco']);
    Route::delete('{empleadoId}/cuentas-banco/{cuentaId}',        [ExpedienteController::class, 'destroyCuentaBanco']);

    // Fecha de ingreso (solo rrhh_admin)
    Route::patch('{empleadoId}/fecha-ingreso',                    [ExpedienteController::class, 'updateFechaIngreso']);
});

// ─── RRHH Admin — Departamentos (portal_admin o rrhh_admin) ─────────────────
Route::prefix('rrhh/admin')->middleware(['auth:sanctum', 'role:portal_admin,rrhh_admin'])->group(function () {
    Route::get('departamentos',                              [DepartamentosController::class, 'index']);
    Route::post('departamentos',                             [DepartamentosController::class, 'store']);
    Route::put('departamentos/{id}',                         [DepartamentosController::class, 'update']);
    Route::delete('departamentos/{id}',                      [DepartamentosController::class, 'destroy']);
    Route::get('departamentos/{id}/empleados',               [DepartamentosController::class, 'empleados']);
    Route::post('departamentos/{id}/empleados/{empId}',      [DepartamentosController::class, 'asignarEmpleado']);
    Route::delete('departamentos/{id}/empleados/{empId}',    [DepartamentosController::class, 'quitarEmpleado']);
    Route::patch('departamentos/{id}/jefe/{empId}',          [DepartamentosController::class, 'asignarJefe']);
    Route::delete('departamentos/{id}/jefe',                 [DepartamentosController::class, 'quitarJefe']);
    Route::get('empleados',                                  [DepartamentosController::class, 'todosEmpleados']);
});
