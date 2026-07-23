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
use App\Http\Controllers\Api\Compras\AuditoriaRecetasController;
use App\Http\Controllers\Api\Compras\InventarioController;
use App\Http\Controllers\Api\Compras\InventarioReporteController;
use App\Http\Controllers\Api\Compras\ExportBriloController;
use App\Http\Controllers\Api\Compras\SolicitudCargaRecetasController;
use App\Http\Controllers\Api\Compras\BrewRecetasController;
use App\Http\Controllers\Api\Compras\BrewLotesController;
use App\Http\Controllers\Api\Compras\BrewCatalogosController;
use App\Http\Controllers\Api\Compras\BrewLevaduraLotesController;
use App\Http\Controllers\Api\PortalController;
use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\RRHH\CatalogosRRHHController;
use App\Http\Controllers\Api\RRHH\DashboardRRHHController;
use App\Http\Controllers\Api\RRHH\PermisosController;
use App\Http\Controllers\Api\RRHH\VacacionesController;
use App\Http\Controllers\Api\RRHH\IncapacidadesController;
use App\Http\Controllers\Api\RRHH\AmonestacionesController;
use App\Http\Controllers\Api\RRHH\DesvinculacionesController;
use App\Http\Controllers\Api\RRHH\RetractacionesController;
use App\Http\Controllers\Api\RRHH\TrasladosController;
use App\Http\Controllers\Api\RRHH\CambiosSalarialesController;
use App\Http\Controllers\Api\RRHH\DepartamentosController;
use App\Http\Controllers\Api\RRHH\ExpedienteController;
use App\Http\Controllers\Api\RRHH\CalendarioController;
use App\Http\Controllers\Api\RRHH\HistorialController;
use App\Http\Controllers\Api\RRHH\AusenciasController;
use App\Http\Controllers\Api\RRHH\ReportesRRHHController;
use App\Http\Controllers\Api\RRHH\HorariosController;
use App\Http\Controllers\Api\RRHH\PlantillasTurnoController;
use App\Http\Controllers\Api\RRHH\PlanillasController;
use App\Http\Controllers\Api\RRHH\MantenimientoPlanillaController;
use App\Http\Controllers\Api\GeoController;
use App\Http\Controllers\Api\Compras\MenuPublicoController;
use App\Http\Controllers\Api\RRHH\IngresoQRController;
use App\Http\Controllers\Api\RRHH\IngresoPersonalController;
use App\Http\Controllers\Api\RRHH\TipoContratoController;
use App\Http\Controllers\Api\RRHH\PlantillaContratoController;
use App\Http\Controllers\Api\RRHH\ContratoEmpleadoController;
use App\Http\Controllers\Api\RRHH\PropinasController;
use App\Http\Controllers\Api\RRHH\PropinasMantenimientoController;

// ─── Geo catálogos El Salvador (público, sin auth) ────────────────────────
Route::prefix('geo')->group(function () {
    Route::get('departamentos',                    [GeoController::class, 'departamentos']);
    Route::get('departamentos/{id}/distritos',     [GeoController::class, 'distritos']);
    Route::get('departamentos/{id}/municipios',   [GeoController::class, 'municipiosPorDepartamento']);
    Route::get('distritos/{id}/municipios',        [GeoController::class, 'municipios']);
    Route::get('municipios/{id}/ubicacion',        [GeoController::class, 'ubicacionMunicipio']);
});

// ─── Menú público (para sistema de reservas, sin auth) ────────────────────
Route::prefix('public')->group(function () {
    Route::get('menu', [MenuPublicoController::class, 'porSucursal']);

    // Formulario público de ingreso de empleado vía QR
    Route::get('ingreso/{token}',  [IngresoQRController::class, 'validar']);
    Route::post('ingreso/{token}', [IngresoQRController::class, 'registrar']);

    // Portal de empleo — sucursales activas (sin auth)
    Route::get('sucursales-activas', function () {
        $sucursales = \App\Models\Sucursal::where(fn($q) => $q->where('activa', true)->orWhereNull('activa'))
            ->orderBy('nombre')
            ->get(['id', 'nombre', 'codigo']);
        return response()->json(['data' => $sucursales]);
    });
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
    Route::post('recetas/simular',                     [RecetasController::class, 'simular']);
    Route::get('recetas/dashboard',                    [RecetasController::class, 'dashboard']);
    Route::get('recetas/costos',                       [RecetasController::class, 'costos']);
    Route::get('recetas/tipos',                        [RecetasController::class, 'tipos']);
    Route::get('recetas/estados',                      [RecetasController::class, 'estados']);
    Route::get('recetas/para-filtro',                  [RecetasController::class, 'paraFiltro']);
    Route::post('recetas/autorizar-masivo',            [RecetasController::class, 'autorizarMasivo']);
    Route::post('recetas/{id}/pdf',                    [RecetasController::class, 'pdf']);
    Route::patch('recetas/{id}/autorizar',             [RecetasController::class, 'autorizar']);
    Route::patch('recetas/{id}/platos-sucursal',       [RecetasController::class, 'setPlatosSucursal']);
    Route::get('upload/presign',                       [RecetasController::class, 'presignUpload']);
    Route::post('upload',                              [RecetasController::class, 'uploadFoto']);
    Route::apiResource('recetas',            RecetasController::class)->only(['index', 'show', 'store', 'update', 'destroy'])->whereNumber('receta');
    // Catálogo de categorías de recetas
    Route::apiResource('receta-categorias',  RecetaCategoriasController::class)->only(['index', 'store', 'update', 'destroy']);

    // Auditorías de recetas
    Route::get('auditorias/dashboard',       [AuditoriaRecetasController::class, 'dashboard']);
    Route::get('auditorias/catalogos',       [AuditoriaRecetasController::class, 'catalogos']);
    Route::get('auditorias/criterios',       [AuditoriaRecetasController::class, 'criterios']);
    Route::get('auditorias',                 [AuditoriaRecetasController::class, 'index']);
    Route::post('auditorias',                [AuditoriaRecetasController::class, 'store']);
    Route::get('auditorias/{id}',            [AuditoriaRecetasController::class, 'show']);
    Route::delete('auditorias/{id}',         [AuditoriaRecetasController::class, 'destroy']);
    Route::get('auditorias/{id}/items',      [AuditoriaRecetasController::class, 'itemsShow']);
    Route::post('auditorias/{id}/items',     [AuditoriaRecetasController::class, 'itemsSave']);
    Route::post('auditorias/{id}/responder', [AuditoriaRecetasController::class, 'responder']);
    Route::post('auditorias/{id}/pdf',      [AuditoriaRecetasController::class, 'pdf']);

    // Ventas semanales
    Route::get('ventas',              [VentasController::class, 'index']);
    Route::get('ventas/sugerencia',   [VentasController::class, 'sugerencia']);
    Route::get('ventas/pivot',          [VentasController::class, 'pivot']);
    Route::get('ventas/consumo-receta',        [VentasController::class, 'consumoReceta']);
    Route::get('ventas/consumo-ingredientes',  [VentasController::class, 'consumoIngredientes']);
    Route::get('ventas/{id}',                  [VentasController::class, 'show']);
    Route::post('ventas/import',      [VentasController::class, 'import']);

    // Exportación BRILO (solo admin_compras / admin_recetas)
    Route::get('export/brilo/materiales-x-producto',  [ExportBriloController::class, 'materialesXProducto']);
    Route::get('export/brilo/productos',               [ExportBriloController::class, 'productos']);
    Route::get('export/brilo/recetas-ven',             [ExportBriloController::class, 'recetasVen']);
    Route::post('export/brilo/resetear-modificados',   [ExportBriloController::class, 'resetearModificados']);

    // Solicitudes de carga a BRILO
    Route::get('solicitudes-carga',        [SolicitudCargaRecetasController::class, 'index']);
    Route::post('solicitudes-carga',       [SolicitudCargaRecetasController::class, 'store']);
    Route::patch('solicitudes-carga/{id}', [SolicitudCargaRecetasController::class, 'update']);

    // Inventario
    Route::get('inventario/estadisticas',       [InventarioController::class, 'estadisticas']);
    Route::get('inventario/reporte-conteo',     [InventarioReporteController::class, 'generar']);
    Route::get('inventario/pedido-sugerido',    [InventarioController::class, 'pedidoSugerido']);
    Route::get('inventario/consumo',            [InventarioController::class, 'consumo']);
    Route::get('inventario/movimientos',        [InventarioController::class, 'movimientos']);
    Route::get('inventario',                    [InventarioController::class, 'index']);
    Route::post('inventario/cargar',            [InventarioController::class, 'cargar']);
    Route::post('inventario/ajuste',            [InventarioController::class, 'ajuste']);
    Route::post('inventario/aplicar-consumo',   [InventarioController::class, 'aplicarConsumo']);
    Route::post('inventario/agregar-al-pedido', [InventarioController::class, 'agregarAlPedido']);
    Route::post('inventario/aplicar-conteo',        [InventarioController::class, 'aplicarConteo']);
    Route::post('inventario/secciones-masivas',     [InventarioController::class, 'asignarSeccionesMasivas']);
    Route::put('inventario/{id}/stock-minimo',  [InventarioController::class, 'actualizarStockMinimo']);
    Route::patch('inventario/{id}/seccion',     [InventarioController::class, 'actualizarSeccion']);
    Route::patch('inventario/{id}/activo',      [InventarioController::class, 'toggleActivo']);
    Route::get('inventario/borrador',           [InventarioController::class, 'getBorrador']);
    Route::put('inventario/borrador',           [InventarioController::class, 'saveBorrador']);
    Route::delete('inventario/borrador',        [InventarioController::class, 'deleteBorrador']);
    Route::get('inventario/conteo-hoy',         [InventarioController::class, 'conteoHoy']);

    // ─── Producción Cervecera ────────────────────────────────────────────────
    Route::prefix('brew')->group(function () {
        // Recetas de producción
        Route::get('recetas',                        [BrewRecetasController::class, 'index']);
        Route::get('recetas/{id}',                   [BrewRecetasController::class, 'show']);
        Route::post('recetas',                       [BrewRecetasController::class, 'store']);
        Route::put('recetas/{id}',                   [BrewRecetasController::class, 'update']);
        Route::delete('recetas/{id}',                [BrewRecetasController::class, 'destroy']);
        Route::get('recetas/{id}/dias-objetivo',     [BrewRecetasController::class, 'diasObjetivo']);
        Route::put('recetas/{id}/dias-objetivo',     [BrewRecetasController::class, 'guardarDiasObjetivo']);

        // Lotes — rutas fijas primero para evitar que {id} las capture
        Route::get('lotes/siguiente-codigo',   [BrewLotesController::class, 'siguienteCodigo']);
        Route::get('lotes',                    [BrewLotesController::class, 'index']);
        Route::post('lotes',                   [BrewLotesController::class, 'store']);
        Route::get('lotes/{id}',               [BrewLotesController::class, 'show']);
        Route::get('lotes/{id}/reporte',       [BrewLotesController::class, 'reporte']);

        // Etapas del wizard
        Route::put('lotes/{id}/coccion',       [BrewLotesController::class, 'guardarCoccion']);
        Route::put('lotes/{id}/filtracion',    [BrewLotesController::class, 'guardarFiltracion']);
        Route::put('lotes/{id}/fermentacion',  [BrewLotesController::class, 'guardarFermentacion']);
        Route::put('lotes/{id}/seguimiento',   [BrewLotesController::class, 'guardarSeguimiento']);
        Route::put('lotes/{id}/llenado',       [BrewLotesController::class, 'guardarLlenado']);
        Route::patch('lotes/{id}/ingredientes/{ingId}', [BrewLotesController::class, 'updateIngrediente']);

        // Utilidades
        Route::get('empleados-planta',         [BrewLotesController::class, 'empleadosPlanta']);
        Route::get('estadisticas',             [BrewLotesController::class, 'estadisticas']);

        // Inventario de levaduras
        Route::get('levadura-lotes',                       [BrewLevaduraLotesController::class, 'index']);
        Route::post('levadura-lotes',                      [BrewLevaduraLotesController::class, 'store']);
        Route::get('levadura-lotes/{id}',                  [BrewLevaduraLotesController::class, 'show']);
        Route::put('levadura-lotes/{id}',                  [BrewLevaduraLotesController::class, 'update']);
        Route::delete('levadura-lotes/{id}',               [BrewLevaduraLotesController::class, 'destroy']);
        Route::post('levadura-lotes/{id}/pitches',         [BrewLevaduraLotesController::class, 'storePitch']);
        Route::delete('levadura-lotes/{id}/pitches/{pid}', [BrewLevaduraLotesController::class, 'destroyPitch']);

        // Catálogos (Brilo + local)
        Route::prefix('catalogos')->group(function () {
            Route::get('maltas',         [BrewCatalogosController::class, 'maltas']);
            Route::get('lupulos',        [BrewCatalogosController::class, 'lupulos']);
            Route::get('cervezas',       [BrewCatalogosController::class, 'cervezas']);
            Route::get('levaduras',       [BrewCatalogosController::class, 'levaduras']);
            Route::get('minerales',       [BrewCatalogosController::class, 'minerales']);
            Route::get('materias-primas',    [BrewCatalogosController::class, 'materiasPrimas']);
            Route::get('ingredientes-boil',  [BrewCatalogosController::class, 'ingredientesBoil']);
        });
    });
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
    Route::post('empleados/{id}/inactivar-duplicado',   [AdminController::class, 'inactivarDuplicado']);
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

// ─── RRHH (protegido con Sanctum + rol jefatura o admin o empleado o gerencia_ops o analistas) ─
Route::prefix('rrhh')->middleware(['auth:sanctum', 'role:jefatura,portal_admin,rrhh_admin,empleado,gerencia_ops,rrhh_analista,rrhh_analista_jr', 'view-as'])->group(function () {

    // Catálogos + equipo a cargo
    Route::get('catalogos', [CatalogosRRHHController::class, 'index']);

    // Dashboard KPIs
    Route::get('dashboard',                  [DashboardRRHHController::class, 'resumen']);
    Route::get('dashboard/demograficos',     [DashboardRRHHController::class, 'demograficos']);
    Route::get('dashboard/charts',           [DashboardRRHHController::class, 'charts']);
    Route::get('pendientes-revision',        [DashboardRRHHController::class, 'pendientesRevision']);

    // Calendario de equipo
    Route::get('calendario', [CalendarioController::class, 'index']);

    // Historial unificado
    Route::get('historial', [HistorialController::class, 'index']);

    // Permisos
    Route::get('permisos/saldos',         [PermisosController::class, 'saldos']);
    Route::get('permisos/saldos-cadejo',  [PermisosController::class, 'saldosCadejo']);
    Route::get('permisos',                [PermisosController::class, 'index']);
    Route::post('permisos',               [PermisosController::class, 'store']);
    Route::get('permisos/{id}',           [PermisosController::class, 'show']);
    Route::put('permisos/{id}',           [PermisosController::class, 'update']);
    Route::delete('permisos/{id}',        [PermisosController::class, 'destroy']);
    Route::get('permisos/{id}/descargar', [PermisosController::class, 'descargar']);

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
    Route::get('incapacidades/{id}/archivo',         [IncapacidadesController::class, 'archivo']);

    // Amonestaciones
    Route::get('amonestaciones',                    [AmonestacionesController::class, 'index']);
    Route::post('amonestaciones',                   [AmonestacionesController::class, 'store']);
    Route::get('amonestaciones/{id}',               [AmonestacionesController::class, 'show']);
    Route::put('amonestaciones/{id}',               [AmonestacionesController::class, 'update']);
    Route::delete('amonestaciones/{id}',            [AmonestacionesController::class, 'destroy']);
    Route::get('amonestaciones/{id}/descargar',     [AmonestacionesController::class, 'descargar']);
    Route::get('amonestaciones/{id}/pdf',           [AmonestacionesController::class, 'pdf']);
    Route::patch('amonestaciones/{id}/invalidar',   [AmonestacionesController::class, 'invalidar']);

    // Ingreso de Personal y Período de Prueba
    Route::get('ingresos/criterios-evaluacion',             [IngresoPersonalController::class, 'criteriosEvaluacion']);
    Route::get('ingresos',                                  [IngresoPersonalController::class, 'index']);
    Route::post('ingresos',                                 [IngresoPersonalController::class, 'store']);
    Route::get('ingresos/{id}',                             [IngresoPersonalController::class, 'show']);
    Route::delete('ingresos/{id}',                          [IngresoPersonalController::class, 'destroy']);
    Route::patch('ingresos/{id}/confirmacion',              [IngresoPersonalController::class, 'confirmar']);
    Route::patch('ingresos/{id}/periodo-prueba',            [IngresoPersonalController::class, 'actualizarPeriodoPrueba']);

    // Contratos por empleado (desde expediente, sin ingreso)
    Route::get('empleados/{empleadoId}/validar-contrato',   [ContratoEmpleadoController::class, 'validarDatos']);
    Route::get('empleados/{empleadoId}/contratos',          [ContratoEmpleadoController::class, 'porEmpleado']);
    Route::post('empleados/{empleadoId}/contratos',         [ContratoEmpleadoController::class, 'storeParaEmpleado']);

    // Contratos por ingreso
    Route::get('ingresos/{ingresoId}/contratos',            [ContratoEmpleadoController::class, 'porIngreso']);
    Route::post('ingresos/{ingresoId}/contratos',           [ContratoEmpleadoController::class, 'store']);

    // Contratos — acciones individuales
    Route::patch('contratos/{id}/estado',                   [ContratoEmpleadoController::class, 'actualizarEstado']);
    Route::get('contratos/{id}/preview',                    [ContratoEmpleadoController::class, 'preview']);
    Route::get('contratos/{id}/pdf',                        [ContratoEmpleadoController::class, 'pdf']);

    // Desvinculaciones (despidos + renuncias, filtrar por ?tipo=despido|renuncia)
    Route::get('desvinculaciones',                  [DesvinculacionesController::class, 'index']);
    Route::post('desvinculaciones',                 [DesvinculacionesController::class, 'store']);
    Route::get('desvinculaciones/{id}',             [DesvinculacionesController::class, 'show']);
    Route::put('desvinculaciones/{id}',             [DesvinculacionesController::class, 'update']);
    Route::delete('desvinculaciones/{id}',          [DesvinculacionesController::class, 'destroy']);
    Route::get('desvinculaciones/{id}/descargar',   [DesvinculacionesController::class, 'descargar']);

    // Retractaciones de renuncia
    Route::get ('retractaciones',                    [RetractacionesController::class, 'index']);
    Route::post ('retractaciones',                   [RetractacionesController::class, 'store']);
    Route::post ('retractaciones/directa',           [RetractacionesController::class, 'directa']);
    Route::post ('retractaciones/{id}/aprobar',      [RetractacionesController::class, 'aprobar']);
    Route::post ('retractaciones/{id}/rechazar',     [RetractacionesController::class, 'rechazar']);

    // Traslados
    Route::get('traslados',         [TrasladosController::class, 'index']);
    Route::post('traslados',        [TrasladosController::class, 'store']);
    Route::get('traslados/{id}',    [TrasladosController::class, 'show']);
    Route::put('traslados/{id}',    [TrasladosController::class, 'update']);
    Route::delete('traslados/{id}', [TrasladosController::class, 'destroy']);

    // Cambios salariales (aumentos + nivelaciones, filtrar por ?tipo_aumento_id=X)
    Route::get('cambios-salariales',                         [CambiosSalarialesController::class, 'index']);
    Route::post('cambios-salariales',                        [CambiosSalarialesController::class, 'store']);
    Route::get('cambios-salariales/{id}',                    [CambiosSalarialesController::class, 'show']);
    Route::put('cambios-salariales/{id}',                    [CambiosSalarialesController::class, 'update']);
    Route::delete('cambios-salariales/{id}',                 [CambiosSalarialesController::class, 'destroy']);
    Route::get('cambios-salariales/{id}/presign',            [CambiosSalarialesController::class, 'presignDocumento']);
    Route::patch('cambios-salariales/{id}/documento',        [CambiosSalarialesController::class, 'confirmarDocumento']);
    Route::delete('cambios-salariales/{id}/documento',       [CambiosSalarialesController::class, 'eliminarDocumento']);

    // Ausencias Injustificadas
    Route::get('ausencias/resumen-mes',  [AusenciasController::class, 'resumenMes']);
    Route::get('ausencias',              [AusenciasController::class, 'index']);
    Route::post('ausencias',             [AusenciasController::class, 'store']);
    Route::delete('ausencias/{id}',          [AusenciasController::class, 'destroy']);
    Route::patch('ausencias/{id}/regularizar',[AusenciasController::class, 'regularizar']);

    // Reportes quincenales
    Route::get('reportes/quincena',  [ReportesRRHHController::class, 'quincena']);
    Route::get('reportes/unidades',  [ReportesRRHHController::class, 'unidades']);

    // Horarios semanales
    Route::get('horarios/mi-horario',             [HorariosController::class, 'miHorario']);
    Route::get('horarios',                        [HorariosController::class, 'index']);
    Route::post('horarios/bulk',                  [HorariosController::class, 'bulk']);
    Route::post('horarios/swap',                  [HorariosController::class, 'swap']);
    Route::delete('horarios/{empleadoId}/{fecha}/{parte}', [HorariosController::class, 'destroy']);
    Route::get('horarios/plantillas',             [PlantillasTurnoController::class, 'index']);
    Route::post('horarios/plantillas',            [PlantillasTurnoController::class, 'store']);
    Route::delete('horarios/plantillas/{id}',     [PlantillasTurnoController::class, 'destroy']);

    // ── Órdenes de descuento (empleado ve las suyas) ──────────────────────────
    Route::get('ordenes-descuento/mis-ordenes', [\App\Http\Controllers\Api\RRHH\OrdenesDescuentoController::class, 'misOrdenes']);

    // ── Bonificaciones (jefe solicita; empleado ve las suyas) ─────────────────
    Route::get('bonificaciones/mis-bonificaciones', [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'misBonificaciones']);
    Route::post('bonificaciones',                   [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'store']);
    Route::get('bonificaciones/tipos',              [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'tipos']);
});

// ─── RRHH Expediente Digital ────────────────────────────────────────────────
// Empleados solo pueden leer su propio expediente (GET); el resto requiere jefatura+
Route::prefix('rrhh/expediente')->middleware(['auth:sanctum', 'role:jefatura,portal_admin,rrhh_admin,empleado,gerencia_ops,rrhh_analista,rrhh_analista_jr', 'view-as'])->group(function () {
    // Cabecera + secciones (empleado puede ver solo el suyo — validado en autorizarAcceso)
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
    Route::get('{empleadoId}/archivos',                               [ExpedienteController::class, 'listArchivos']);
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

// ─── RRHH Planillas (solo rrhh_admin) ────────────────────────────────────────
Route::prefix('rrhh/planillas')->middleware(['auth:sanctum', 'role:rrhh_admin'])->group(function () {

    // IMPORTANTE: rutas estáticas ANTES de {id} para evitar conflictos de orden
    Route::get('mantenimiento/config',              [MantenimientoPlanillaController::class, 'getConfig']);
    Route::put('mantenimiento/config',              [MantenimientoPlanillaController::class, 'updateConfig']);
    Route::get('mantenimiento/renta',               [MantenimientoPlanillaController::class, 'getTablaRenta']);
    Route::put('mantenimiento/renta',               [MantenimientoPlanillaController::class, 'updateTablaRenta']);
    Route::get('mantenimiento/acreedores',          [MantenimientoPlanillaController::class, 'getAcreedores']);
    Route::post('mantenimiento/acreedores',         [MantenimientoPlanillaController::class, 'storeAcreedor']);
    Route::put('mantenimiento/acreedores/{id}',     [MantenimientoPlanillaController::class, 'updateAcreedor']);
    Route::patch('mantenimiento/acreedores/{id}/toggle', [MantenimientoPlanillaController::class, 'toggleAcreedor']);
    Route::get('mantenimiento/ordenes',             [MantenimientoPlanillaController::class, 'getOrdenes']);
    Route::post('mantenimiento/ordenes',            [MantenimientoPlanillaController::class, 'storeOrden']);
    Route::put('mantenimiento/ordenes/{id}',        [MantenimientoPlanillaController::class, 'updateOrden']);
    Route::delete('mantenimiento/ordenes/{id}',     [MantenimientoPlanillaController::class, 'deleteOrden']);

    // Tipos de contrato
    Route::get('mantenimiento/contratos/tipos',             [TipoContratoController::class, 'index']);
    Route::post('mantenimiento/contratos/tipos',            [TipoContratoController::class, 'store']);
    Route::put('mantenimiento/contratos/tipos/{id}',        [TipoContratoController::class, 'update']);
    Route::patch('mantenimiento/contratos/tipos/{id}/toggle',[TipoContratoController::class, 'toggle']);
    Route::delete('mantenimiento/contratos/tipos/{id}',     [TipoContratoController::class, 'destroy']);

    // Plantillas de contrato
    Route::get('mantenimiento/contratos/plantillas',             [PlantillaContratoController::class, 'index']);
    Route::post('mantenimiento/contratos/plantillas',            [PlantillaContratoController::class, 'store']);
    Route::get('mantenimiento/contratos/plantillas/{id}',        [PlantillaContratoController::class, 'show']);
    Route::put('mantenimiento/contratos/plantillas/{id}',        [PlantillaContratoController::class, 'update']);
    Route::patch('mantenimiento/contratos/plantillas/{id}/toggle',[PlantillaContratoController::class, 'toggle']);
    Route::delete('mantenimiento/contratos/plantillas/{id}',     [PlantillaContratoController::class, 'destroy']);

    // Generar ANTES de {id} para evitar que "generar" sea interpretado como {id}
    Route::post('generar',                          [PlanillasController::class, 'generar']);

    // CRUD planillas
    Route::get('',                                  [PlanillasController::class, 'index']);
    Route::get('{id}',                              [PlanillasController::class, 'show']);
    Route::put('{id}/aprobar',                      [PlanillasController::class, 'aprobar']);
    Route::get('{id}/exportar',                     [PlanillasController::class, 'exportar']);
    Route::get('{id}/boleta/{empleadoId}',          [PlanillasController::class, 'boletaPdf']);
});

// ─── RRHH Propinas (solo rrhh_admin) ─────────────────────────────────────────
Route::prefix('rrhh/propinas')->middleware(['auth:sanctum', 'role:rrhh_admin'])->group(function () {
    // Mantenimiento
    Route::get('mantenimiento/config-sucursales',          [PropinasMantenimientoController::class, 'getConfigSucursales']);
    Route::post('mantenimiento/config-sucursales',         [PropinasMantenimientoController::class, 'storeConfigSucursal']);
    Route::put('mantenimiento/config-sucursales/{id}',     [PropinasMantenimientoController::class, 'updateConfigSucursal']);
    Route::get('mantenimiento/puntos-cargo',               [PropinasMantenimientoController::class, 'getPuntosCargo']);
    Route::post('mantenimiento/puntos-cargo',              [PropinasMantenimientoController::class, 'upsertPuntosCargo']);
    Route::get('mantenimiento/puntos-empleado',            [PropinasMantenimientoController::class, 'getPuntosEmpleado']);
    Route::post('mantenimiento/puntos-empleado',           [PropinasMantenimientoController::class, 'storePuntosEmpleado']);
    Route::put('mantenimiento/puntos-empleado/{id}',       [PropinasMantenimientoController::class, 'updatePuntosEmpleado']);
    Route::delete('mantenimiento/puntos-empleado/{id}',    [PropinasMantenimientoController::class, 'destroyPuntosEmpleado']);
    Route::get('mantenimiento/adicionales',                [PropinasMantenimientoController::class, 'getAdicionales']);
    Route::post('mantenimiento/adicionales',               [PropinasMantenimientoController::class, 'storeAdicional']);
    Route::put('mantenimiento/adicionales/{id}',           [PropinasMantenimientoController::class, 'updateAdicional']);
    Route::delete('mantenimiento/adicionales/{id}',        [PropinasMantenimientoController::class, 'destroyAdicional']);
    Route::get('mantenimiento/flujos',                     [PropinasMantenimientoController::class, 'getFlujos']);
    Route::post('mantenimiento/flujos',                    [PropinasMantenimientoController::class, 'storeFlujo']);
    Route::delete('mantenimiento/flujos/{id}',             [PropinasMantenimientoController::class, 'destroyFlujo']);

    // Períodos operativos
    Route::get('sobrantes',                                [PropinasController::class, 'getSobrantes']);
    Route::get('',                                         [PropinasController::class, 'index']);
    Route::post('',                                        [PropinasController::class, 'store']);
    Route::get('{id}',                                     [PropinasController::class, 'show']);
    Route::post('{id}/calcular',                           [PropinasController::class, 'calcular']);
    Route::put('{id}/detalles/{detalleId}',                [PropinasController::class, 'updateDetalle']);
    Route::post('{id}/aprobar',                            [PropinasController::class, 'aprobar']);
    Route::post('{id}/integrar-planilla',                  [PropinasController::class, 'integrarAPlanilla']);
});

// ─── RRHH Admin — Departamentos (portal_admin, rrhh_admin, rrhh_analista) ────
Route::prefix('rrhh/admin')->middleware(['auth:sanctum', 'role:portal_admin,rrhh_admin,rrhh_analista'])->group(function () {
    Route::get('view-as/{identifier}',                       [\App\Http\Controllers\Api\RRHH\ViewAsController::class, 'lookup']);
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

    // ── Error logs (visible solo para GEN_INF en el frontend) ────────────
    Route::get('error-logs',                                 [\App\Http\Controllers\Api\RRHH\ErrorLogsController::class, 'index']);
    Route::get('error-logs/stats',                           [\App\Http\Controllers\Api\RRHH\ErrorLogsController::class, 'stats']);
    Route::patch('error-logs/resolver-todos',                [\App\Http\Controllers\Api\RRHH\ErrorLogsController::class, 'resolverTodos']);
    Route::patch('error-logs/{id}/resolver',                 [\App\Http\Controllers\Api\RRHH\ErrorLogsController::class, 'resolver']);
    Route::delete('error-logs/{id}',                         [\App\Http\Controllers\Api\RRHH\ErrorLogsController::class, 'destroy']);
    Route::delete('error-logs',                              [\App\Http\Controllers\Api\RRHH\ErrorLogsController::class, 'clear']);

    // ── Ingreso QR (generación de tokens y pre-registros, solo rrhh_admin) ──
    Route::post('ingreso-qr/generar',   [IngresoQRController::class, 'generar']);
    Route::get('ingreso-qr/tokens',     [IngresoQRController::class, 'listar']);
    Route::get('ingreso-qr/registros',  [IngresoQRController::class, 'registros']);

    // ── Catálogo de puestos (cargos) ─────────────────────────────────────────
    Route::get('cargos',                [\App\Http\Controllers\Api\RRHH\CargosController::class, 'index']);
    Route::post('cargos',               [\App\Http\Controllers\Api\RRHH\CargosController::class, 'store']);
    Route::put('cargos/{id}',           [\App\Http\Controllers\Api\RRHH\CargosController::class, 'update']);
    Route::patch('cargos/{id}/toggle',  [\App\Http\Controllers\Api\RRHH\CargosController::class, 'toggleActivo']);
    Route::get('cargos/{id}/headcount', [\App\Http\Controllers\Api\RRHH\CargosController::class, 'headcount']);
    Route::put('cargos/{id}/headcount', [\App\Http\Controllers\Api\RRHH\CargosController::class, 'updateHeadcount']);

    // ── Catálogo de plazas ────────────────────────────────────────────────────
    Route::get('plazas/stats',          [\App\Http\Controllers\Api\RRHH\PlazasController::class, 'stats']);
    Route::get('plazas/exceso',         [\App\Http\Controllers\Api\RRHH\PlazasController::class, 'exceso']);
    Route::get('plazas',                [\App\Http\Controllers\Api\RRHH\PlazasController::class, 'index']);
    Route::post('plazas',               [\App\Http\Controllers\Api\RRHH\PlazasController::class, 'store']);
    Route::put('plazas/{id}',           [\App\Http\Controllers\Api\RRHH\PlazasController::class, 'update']);
    Route::patch('plazas/{id}/toggle',  [\App\Http\Controllers\Api\RRHH\PlazasController::class, 'toggleActivo']);
    Route::get('plazas/{id}/historial', [\App\Http\Controllers\Api\RRHH\PlazasController::class, 'historial']);
    Route::delete('plazas/{id}',        [\App\Http\Controllers\Api\RRHH\PlazasController::class, 'destroy']);

    // ── Tipos de acreedor ─────────────────────────────────────────────────────
    Route::get('tipos-acreedor',               [\App\Http\Controllers\Api\RRHH\AcreedoresController::class, 'tiposIndex']);
    Route::post('tipos-acreedor',              [\App\Http\Controllers\Api\RRHH\AcreedoresController::class, 'tiposStore']);
    Route::put('tipos-acreedor/{id}',          [\App\Http\Controllers\Api\RRHH\AcreedoresController::class, 'tiposUpdate']);
    Route::patch('tipos-acreedor/{id}/toggle', [\App\Http\Controllers\Api\RRHH\AcreedoresController::class, 'tiposToggle']);

    // ── Catálogo de acreedores ────────────────────────────────────────────────
    Route::get('acreedores',               [\App\Http\Controllers\Api\RRHH\AcreedoresController::class, 'index']);
    Route::post('acreedores',              [\App\Http\Controllers\Api\RRHH\AcreedoresController::class, 'store']);
    Route::put('acreedores/{id}',          [\App\Http\Controllers\Api\RRHH\AcreedoresController::class, 'update']);
    Route::patch('acreedores/{id}/toggle', [\App\Http\Controllers\Api\RRHH\AcreedoresController::class, 'toggleActivo']);

    // ── Órdenes de descuento (gestión admin) ──────────────────────────────────
    Route::get('ordenes-descuento/estados',         [\App\Http\Controllers\Api\RRHH\OrdenesDescuentoController::class, 'estados']);
    Route::get('ordenes-descuento',                 [\App\Http\Controllers\Api\RRHH\OrdenesDescuentoController::class, 'index']);
    Route::post('ordenes-descuento',                [\App\Http\Controllers\Api\RRHH\OrdenesDescuentoController::class, 'store']);
    Route::put('ordenes-descuento/{id}',            [\App\Http\Controllers\Api\RRHH\OrdenesDescuentoController::class, 'update']);
    Route::patch('ordenes-descuento/{id}/estado',            [\App\Http\Controllers\Api\RRHH\OrdenesDescuentoController::class, 'cambiarEstado']);
    Route::post('ordenes-descuento/{id}/documento',          [\App\Http\Controllers\Api\RRHH\OrdenesDescuentoController::class, 'subirDocumento']);
    Route::get('ordenes-descuento/{id}/documento',           [\App\Http\Controllers\Api\RRHH\OrdenesDescuentoController::class, 'descargarDocumento']);
    Route::post('ordenes-descuento/{id}/finiquitar',         [\App\Http\Controllers\Api\RRHH\OrdenesDescuentoController::class, 'finiquitar']);
    Route::get('ordenes-descuento/{id}/documento-finiquito', [\App\Http\Controllers\Api\RRHH\OrdenesDescuentoController::class, 'descargarFiniquito']);

    // ── Tipos de bonificación ─────────────────────────────────────────────────
    Route::get('tipos-bonificacion',               [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'tipos']);
    Route::post('tipos-bonificacion',              [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'tiposStore']);
    Route::put('tipos-bonificacion/{id}',          [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'tiposUpdate']);
    Route::patch('tipos-bonificacion/{id}/toggle', [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'tiposToggle']);

    // ── Bonificaciones (gestión admin: aprobar / rechazar / aplicar) ──────────
    Route::get('bonificaciones/estados',          [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'estados']);
    Route::get('bonificaciones',                  [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'index']);
    Route::patch('bonificaciones/{id}/aprobar',   [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'aprobar']);
    Route::patch('bonificaciones/{id}/rechazar',  [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'rechazar']);
    Route::patch('bonificaciones/{id}/aplicar',   [\App\Http\Controllers\Api\RRHH\BonificacionesController::class, 'aplicar']);

    // KPIs
    Route::get('kpi-plantillas/cargos-disponibles', [\App\Http\Controllers\Api\RRHH\KpiPlantillasController::class, 'cargosDisponibles']);
    Route::get('kpi-plantillas',                    [\App\Http\Controllers\Api\RRHH\KpiPlantillasController::class, 'index']);
    Route::post('kpi-plantillas',                   [\App\Http\Controllers\Api\RRHH\KpiPlantillasController::class, 'store']);
    Route::get('kpi-plantillas/{id}',               [\App\Http\Controllers\Api\RRHH\KpiPlantillasController::class, 'show']);
    Route::put('kpi-plantillas/{id}',               [\App\Http\Controllers\Api\RRHH\KpiPlantillasController::class, 'update']);
    Route::patch('kpi-plantillas/{id}/toggle',      [\App\Http\Controllers\Api\RRHH\KpiPlantillasController::class, 'toggleActivo']);
    Route::get('kpi-plantillas/{id}/empleados',     [\App\Http\Controllers\Api\RRHH\KpiPlantillasController::class, 'empleadosAfectados']);
    Route::delete('kpi-plantillas/{id}',            [\App\Http\Controllers\Api\RRHH\KpiPlantillasController::class, 'destroy']);
    Route::get('kpi-resultados/resumen',            [\App\Http\Controllers\Api\RRHH\KpiResultadosController::class, 'resumen']);
    Route::get('kpi-resultados',                    [\App\Http\Controllers\Api\RRHH\KpiResultadosController::class, 'index']);
    Route::post('kpi-resultados/preview',           [\App\Http\Controllers\Api\RRHH\KpiResultadosController::class, 'preview']);
    Route::post('kpi-resultados/aplicar',           [\App\Http\Controllers\Api\RRHH\KpiResultadosController::class, 'aplicar']);
});

// ─── CADEJO VENTAS ───────────────────────────────────────────────────────────
Route::prefix('cadejo-ventas')->group(function () {
    // Auth mock (sin Sanctum — prototipo)
    Route::post('auth/login', [\App\Http\Controllers\Api\Ventas\AuthController::class, 'login']);

    // Dashboard
    Route::get('dashboard', [\App\Http\Controllers\Api\Ventas\DashboardController::class, 'index']);

    // Clientes
    Route::get('clientes',        [\App\Http\Controllers\Api\Ventas\ClientesController::class, 'index']);
    Route::post('clientes',       [\App\Http\Controllers\Api\Ventas\ClientesController::class, 'store']);
    Route::get('clientes/{id}',   [\App\Http\Controllers\Api\Ventas\ClientesController::class, 'show']);
    Route::patch('clientes/{id}', [\App\Http\Controllers\Api\Ventas\ClientesController::class, 'update']);

    // Productos
    Route::get('productos', [\App\Http\Controllers\Api\Ventas\ProductosController::class, 'index']);

    // Órdenes
    Route::get('ordenes',                     [\App\Http\Controllers\Api\Ventas\OrdenesController::class, 'index']);
    Route::post('ordenes',                    [\App\Http\Controllers\Api\Ventas\OrdenesController::class, 'store']);
    Route::get('ordenes/{id}',                [\App\Http\Controllers\Api\Ventas\OrdenesController::class, 'show']);
    Route::patch('ordenes/{id}',              [\App\Http\Controllers\Api\Ventas\OrdenesController::class, 'update']);
    Route::patch('ordenes/{id}/despachar',    [\App\Http\Controllers\Api\Ventas\OrdenesController::class, 'marcarDespachada']);
    Route::patch('ordenes/{id}/facturar',     [\App\Http\Controllers\Api\Ventas\OrdenesController::class, 'marcarFacturado']);

    // Pagos de órdenes
    Route::get('ordenes/{id}/pagos',                        [\App\Http\Controllers\Api\Ventas\PagosController::class, 'index']);
    Route::post('ordenes/{id}/pagos',                       [\App\Http\Controllers\Api\Ventas\PagosController::class, 'store']);
    Route::get('ordenes/{id}/pagos/{pid}/comprobante',      [\App\Http\Controllers\Api\Ventas\PagosController::class, 'downloadComprobante']);
    Route::delete('ordenes/{id}/pagos/{pid}',               [\App\Http\Controllers\Api\Ventas\PagosController::class, 'destroy']);

    // Aprobaciones
    Route::get('aprobaciones',               [\App\Http\Controllers\Api\Ventas\AprobacionesController::class, 'index']);
    Route::patch('aprobaciones/{id}/resolver', [\App\Http\Controllers\Api\Ventas\AprobacionesController::class, 'resolver']);

    // Exportación
    Route::get('ordenes/{id}/export/excel',  [\App\Http\Controllers\Api\Ventas\ExportController::class, 'ordenExcel']);
    Route::get('export/brilo-facturas',      [\App\Http\Controllers\Api\Ventas\ExportController::class, 'briloFacturas']);
    Route::get('export/brilo-clientes',      [\App\Http\Controllers\Api\Ventas\ExportController::class, 'briloClientes']);
    Route::get('export/contabilidad-csv',    [\App\Http\Controllers\Api\Ventas\ExportController::class, 'contabilidadCsv']);

    // Devoluciones / Cambios
    Route::get('devoluciones',               [\App\Http\Controllers\Api\Ventas\DevolucionesController::class, 'index']);
    Route::post('devoluciones',              [\App\Http\Controllers\Api\Ventas\DevolucionesController::class, 'store']);
    Route::get('devoluciones/{id}',          [\App\Http\Controllers\Api\Ventas\DevolucionesController::class, 'show']);
    Route::patch('devoluciones/{id}/estado', [\App\Http\Controllers\Api\Ventas\DevolucionesController::class, 'update']);

    // Documentos de clientes
    Route::get('clientes/{id}/documentos',          [\App\Http\Controllers\Api\Ventas\ClienteDocumentosController::class, 'index']);
    Route::post('clientes/{id}/documentos',         [\App\Http\Controllers\Api\Ventas\ClienteDocumentosController::class, 'store']);
    Route::get('clientes/{id}/documentos/{did}',    [\App\Http\Controllers\Api\Ventas\ClienteDocumentosController::class, 'download']);
    Route::delete('clientes/{id}/documentos/{did}', [\App\Http\Controllers\Api\Ventas\ClienteDocumentosController::class, 'destroy']);

    // Catálogos de precios
    Route::get('catalogos-precio',                          [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'index']);
    Route::post('catalogos-precio',                         [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'store']);
    Route::patch('catalogos-precio/{id}',                   [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'update']);
    Route::patch('catalogos-precio/{id}/toggle',            [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'toggle']);
    Route::delete('catalogos-precio/{id}',                  [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'destroy']);
    Route::get('catalogos-precio/{id}/lineas',              [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'lineas']);
    Route::post('catalogos-precio/{id}/lineas',             [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'storeLinea']);
    Route::patch('catalogos-precio/{id}/lineas/{lineaId}',  [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'updateLinea']);
    Route::delete('catalogos-precio/{id}/lineas/{lineaId}', [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'destroyLinea']);
    Route::get('catalogos-precio/{id}/para-orden',          [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'paraOrden']);
    Route::post('catalogos-precio/{id}/ajuste-masivo',      [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'ajusteMasivo']);
    Route::patch('catalogos-precio/{id}/lineas/batch',      [\App\Http\Controllers\Api\Ventas\CatalogosPrecioController::class, 'batchUpdate']);

    // Promociones de venta
    Route::get('promociones-venta',               [\App\Http\Controllers\Api\Ventas\PromocionesVentaController::class, 'index']);
    Route::get('promociones-venta/activas',        [\App\Http\Controllers\Api\Ventas\PromocionesVentaController::class, 'activas']);
    Route::post('promociones-venta',               [\App\Http\Controllers\Api\Ventas\PromocionesVentaController::class, 'store']);
    Route::patch('promociones-venta/{id}',         [\App\Http\Controllers\Api\Ventas\PromocionesVentaController::class, 'update']);
    Route::patch('promociones-venta/{id}/toggle',  [\App\Http\Controllers\Api\Ventas\PromocionesVentaController::class, 'toggle']);
    Route::delete('promociones-venta/{id}',        [\App\Http\Controllers\Api\Ventas\PromocionesVentaController::class, 'destroy']);
});
