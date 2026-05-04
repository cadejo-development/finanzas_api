<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta datos del sistema al formato CSV de importación BRILO ERP.
 *
 * Rutas:
 *   GET /api/compras/export/brilo/materiales-x-producto  → INV_Formato Importacion Materiales X Producto
 *   GET /api/compras/export/brilo/productos               → VEN_Formato Importacion Productos y Servicios
 *
 * Acceso restringido a roles: admin_compras, admin_recetas
 */
class ExportBriloController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/compras/export/brilo/materiales-x-producto
    //
    // Exporta ingredientes de recetas al formato INV de BRILO.
    // Columnas: A=Cód.Producto Padre, B=Cód.Producto MP, C=Cantidad MP,
    //           D=Activo?, E=Detiene Explotación?, F=Cód.Ubicación,
    //           G=Cód.Presentación, H=Cantidad Presentación
    //
    // Parámetros query opcionales:
    //   nivel              = 1 | 2 | 3 (ver detalle abajo)
    //   tipo_receta        = plato | sub_receta | (vacío = todos)
    //   solo_con_codigo    = 1  → solo recetas que tienen codigo_origen
    //   estado_id          = ID del estado de receta para filtrar
    //   solo_modificados   = 1 (default) → solo recetas con modificado_localmente = true
    //
    // Niveles de importación (orden obligatorio en BRILO):
    //   nivel=1 → Sub-recetas cuyos ingredientes son SOLO materias primas (importar primero)
    //   nivel=2 → Sub-recetas que llevan otras sub-recetas como ingredientes (importar segundo)
    //   nivel=3 → Platos (importar último)
    // ──────────────────────────────────────────────────────────────────────────
    public function materialesXProducto(Request $request): StreamedResponse
    {
        $this->requireAdminRol();

        $nivel            = $request->query('nivel') ? (int) $request->query('nivel') : null;
        $tipoReceta       = $request->query('tipo_receta');
        $soloConCodigo    = (bool) $request->query('solo_con_codigo', 1);
        $estadoId         = $request->query('estado_id') ? (int) $request->query('estado_id') : null;
        $soloModificados  = (bool) $request->query('solo_modificados', 1);

        // ── Consulta principal ──────────────────────────────────────────────
        // Recetas activas + sus ingredientes (productos y sub-recetas)
        $query = DB::connection('compras')
            ->table('receta_ingredientes as ri')
            ->join('recetas as r', 'ri.receta_id', '=', 'r.id')
            ->leftJoin('productos as p',  'ri.producto_id',  '=', 'p.id')
            ->leftJoin('recetas as sr',   'ri.sub_receta_id','=', 'sr.id')   // sub-receta como ingrediente
            ->where('r.activa', true)
            ->select([
                'r.codigo_origen        as receta_codigo',
                'r.nombre               as receta_nombre',
                'r.tipo_receta',
                'p.codigo               as prod_codigo',
                'sr.codigo_origen       as sub_codigo',
                'ri.cantidad_por_plato',
                'ri.unidad',
            ]);

        if ($soloModificados) {
            $query->where('r.modificado_localmente', true);
        }

        if ($soloConCodigo) {
            $query->whereNotNull('r.codigo_origen')->where('r.codigo_origen', '!=', '');
        }

        // ── Filtro por nivel de importación ────────────────────────────────
        if ($nivel === 1) {
            // Sub-recetas cuyos ingredientes son SOLO materias primas
            $query->where('r.tipo_receta', 'sub_receta')
                  ->whereNotIn('r.id', function ($sub) {
                      $sub->select('receta_id')
                          ->from('receta_ingredientes')
                          ->whereNotNull('sub_receta_id');
                  });
        } elseif ($nivel === 2) {
            // Sub-recetas que llevan otras sub-recetas como ingredientes
            $query->where('r.tipo_receta', 'sub_receta')
                  ->whereIn('r.id', function ($sub) {
                      $sub->select('receta_id')
                          ->from('receta_ingredientes')
                          ->whereNotNull('sub_receta_id');
                  });
        } elseif ($nivel === 3) {
            // Platos
            $query->where(fn ($q) => $q->where('r.tipo_receta', 'plato')->orWhereNull('r.tipo_receta'))
                  ->whereRaw("lower(coalesce(r.tipo_receta,'')) NOT LIKE '%sub%receta%'");
        } elseif ($tipoReceta === 'plato') {
            $query->where(fn ($q) => $q->where('r.tipo_receta', 'plato')->orWhereNull('r.tipo_receta'))
                  ->whereRaw("lower(coalesce(r.tipo,'')) NOT LIKE '%sub%receta%'");
        } elseif ($tipoReceta === 'sub_receta') {
            $query->where(fn ($q) => $q->where('r.tipo_receta', 'sub_receta')
                ->orWhereRaw("lower(coalesce(r.tipo,'')) LIKE '%sub%receta%'"));
        }

        if ($estadoId) {
            $query->where('r.estado_id', $estadoId);
        }

        $filas = $query->orderBy('r.codigo_origen')->orderBy('ri.id')->get();

        // ── Nombre de archivo según nivel ──────────────────────────────────
        $nivelSufijo = match($nivel) {
            1 => '_Nivel1_SubRecetas_Simples_',
            2 => '_Nivel2_SubRecetas_Compuestas_',
            3 => '_Nivel3_Platos_',
            default => '_',
        };
        $filename = 'INV_Materiales_X_Producto' . $nivelSufijo . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($filas) {
            $handle = fopen('php://output', 'w');

            // BOM UTF-8 para compatibilidad con Excel
            fwrite($handle, "\xEF\xBB\xBF");

            // Cabecera según formato INV de BRILO
            fputcsv($handle, [
                'Código Producto Padre',   // A
                'Código Producto MP',      // B
                'Cantidad MP',             // C
                'Activo?',                 // D
                'Detiene Explotación?',    // E
                'Código Ubicación',        // F
                'Código Presentación',     // G
                'Cantidad Presentación',   // H
            ]);

            foreach ($filas as $fila) {
                // Col B: código del ingrediente. Si es sub-receta usa su codigo_origen, si no, el código del producto.
                $esSub = !is_null($fila->sub_codigo);
                $codIngrediente = $fila->sub_codigo ?? $fila->prod_codigo ?? '';
                if (!$codIngrediente) continue; // ingrediente sin código → omitir

                /*
                 * Regla de columnas C vs G/H:
                 *   - Materia prima (MR*): cantidad en C, BRILO conoce su presentación internamente.
                 *   - Sub-receta (PL*):    C vacío, G = código presentación local, H = cantidad.
                 *     Razón: BRILO usa la unidad base del ingrediente-PL (LB, TDA, etc.) al leer C,
                 *     pero al leer G+H usa el código de presentación para convertir automáticamente.
                 */
                if ($esSub) {
                    $codPres = $this->unidadACodigoBrilo($fila->unidad ?? '');
                    fputcsv($handle, [
                        $fila->receta_codigo ?? '',               // A
                        $codIngrediente,                          // B
                        '',                                       // C - vacío para sub-recetas
                        'SI',                                     // D
                        '',                                       // E
                        '',                                       // F
                        $codPres,                                 // G - Código Presentación
                        $this->formatNum($fila->cantidad_por_plato), // H - Cantidad Presentación
                    ]);
                } else {
                    fputcsv($handle, [
                        $fila->receta_codigo ?? '',               // A
                        $codIngrediente,                          // B
                        $this->formatNum($fila->cantidad_por_plato), // C
                        'SI',                                     // D
                        '',                                       // E
                        '',                                       // F
                        '',                                       // G
                        '',                                       // H
                    ]);
                }
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/compras/export/brilo/sub-recetas-ven
    //
    // Exporta sub-recetas al formato VEN de BRILO, para poder crearlas como
    // productos en BRILO ANTES de importar los archivos INV de materiales.
    // 46 columnas según el formato oficial.
    //
    // Parámetros query opcionales:
    //   nivel            = 1 | 2  → mismo criterio que en materialesXProducto
    //   solo_con_codigo  = 1 (default) → solo sub-recetas con codigo_origen
    //   solo_modificados = 1 (default) → solo con modificado_localmente = true
    // ──────────────────────────────────────────────────────────────────────────
    public function subRecetasVen(Request $request): StreamedResponse
    {
        $this->requireAdminRol();

        $nivel           = $request->query('nivel') ? (int) $request->query('nivel') : null;
        $soloConCodigo   = (bool) $request->query('solo_con_codigo', 1);
        $soloModificados = (bool) $request->query('solo_modificados', 1);

        $query = DB::connection('compras')
            ->table('recetas as r')
            ->where('r.tipo_receta', 'sub_receta')
            ->where('r.activa', true)
            ->select([
                'r.id',
                'r.codigo_origen',
                'r.nombre',
                'r.rendimiento',
                'r.rendimiento_unidad',
                'r.precio',
                'r.activa',
            ]);

        if ($soloModificados) {
            $query->where('r.modificado_localmente', true);
        }

        if ($soloConCodigo) {
            $query->whereNotNull('r.codigo_origen')->where('r.codigo_origen', '!=', '');
        }

        if ($nivel === 1) {
            // Sub-recetas simples: sus ingredientes son SOLO materias primas
            $query->whereNotIn('r.id', function ($sub) {
                $sub->select('receta_id')
                    ->from('receta_ingredientes')
                    ->whereNotNull('sub_receta_id');
            });
        } elseif ($nivel === 2) {
            // Sub-recetas compuestas: llevan otras sub-recetas como ingredientes
            $query->whereIn('r.id', function ($sub) {
                $sub->select('receta_id')
                    ->from('receta_ingredientes')
                    ->whereNotNull('sub_receta_id');
            });
        }

        $filas = $query->orderBy('r.codigo_origen')->get();

        $nivelSufijo = match($nivel) {
            1 => '_Nivel1_',
            2 => '_Nivel2_',
            default => '_',
        };
        $filename = 'VEN_SubRecetas' . $nivelSufijo . now()->format('Ymd_His') . '.csv';

        $cabecera = $this->venCabecera();

        return response()->streamDownload(function () use ($filas, $cabecera) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $cabecera);

            foreach ($filas as $fila) {
                $precioLista = (float) ($fila->precio ?? 0) > 0
                    ? $this->formatNum($fila->precio)
                    : '';

                $fila46 = [
                    $fila->codigo_origen,                   // A - Código Único
                    $fila->nombre,                          // B - Descripción
                    'P',                                    // C - Tipo: Inventariado
                    '',                                     // D - Modelo
                    $precioLista,                           // E - Precio de Lista
                    'NO',                                   // F - Exento
                    '',                                     // G - Cat Padre
                    '',                                     // H - Cat Hija
                    $fila->rendimiento_unidad ?? '',        // I - Presentación Base
                    '', '', '', '',                         // J-M - Precios venta 2-5
                    '', '', '',                             // N-P - Cuentas contables (completar manualmente)
                    '', '',                                 // Q-R - CodBarra1-2
                    '',                                     // S - Marca
                    'NO',                                   // T - Avería
                    'NO',                                   // U - No Comisionable
                    '',                                     // V - % Variación Costo
                    '', '', '',                             // W-Y - Estilo, Talla, Color
                    'SI',                                   // Z - MostrarEnVentas
                    'SI',                                   // AA - MostrarEnCompras
                    '', '',                                 // AB-AC - CC Variación
                    '',                                     // AD - Precio Sugerido
                    '', '',                                 // AE-AF - Meta Inv / Cadena
                    '',                                     // AG - Código Ubicación Default
                    'NO',                                   // AH - Requiere Lote
                    $fila->activa ? 'SI' : 'NO',           // AI - Activo
                    'NO',                                   // AJ - Requiere Serie
                    '',                                     // AK - Factor Ganancia
                    'NO', 'NO',                             // AL-AM - Viñeta
                    'NO',                                   // AN - Agrupar por Cat.
                    '',                                     // AO - Descripción Extendida
                    '', '',                                 // AP-AQ - Centro de Costos
                    '',                                     // AR - #Días Abastecerse
                    '',                                     // AS - Código Partida Arancelaria
                    '',                                     // AT - Cuenta Inventario en Proceso
                ];

                fputcsv($handle, $fila46);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/compras/export/brilo/productos
    //
    // Exporta materias primas al formato VEN de BRILO.
    // 46 columnas según el formato oficial.
    //
    // Parámetros query opcionales:
    //   solo_activos     = 1 (default) | 0
    //   solo_modificados = 1 (default) → solo productos con modificado_localmente = true
    // ──────────────────────────────────────────────────────────────────────────
    public function productos(Request $request): StreamedResponse
    {
        $this->requireAdminRol();

        $soloActivos     = (bool) $request->query('solo_activos', 1);
        $soloModificados = (bool) $request->query('solo_modificados', 1);

        $query = DB::connection('compras')
            ->table('productos as p')
            ->leftJoin('categorias as c', 'p.categoria_id', '=', 'c.id')
            ->select([
                'p.codigo',
                'p.nombre',
                'p.unidad',
                'p.precio',
                'p.costo',
                'p.activo',
                'c.nombre as categoria_nombre',
            ]);

        if ($soloModificados) {
            $query->where('p.modificado_localmente', true);
        }

        if ($soloActivos) {
            $query->where('p.activo', true);
        }

        $filas = $query->orderBy('p.codigo')->get();

        $filename = 'VEN_Productos_Servicios_' . now()->format('Ymd_His') . '.csv';

        $cabecera = $this->venCabecera();

        return response()->streamDownload(function () use ($filas, $cabecera) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $cabecera);

            foreach ($filas as $fila) {
                // Precio de lista: usamos precio si existe; si no, el costo
                $precioLista = (float) $fila->precio > 0
                    ? $this->formatNum($fila->precio)
                    : $this->formatNum($fila->costo);

                $fila46 = [
                    $fila->codigo,           // A
                    $fila->nombre,           // B
                    'P',                     // C  - Producto Inventariado (ajustar si aplica S/AC/etc.)
                    '',                      // D  - Modelo
                    $precioLista,            // E
                    'NO',                    // F  - Exento
                    '',                      // G  - Cat Padre (completar manualmente)
                    '',                      // H  - Cat Hija
                    $fila->unidad ?? '',     // I  - Presentación Base
                    '',                      // J  - NomPrecioVenta2
                    '',                      // K
                    '',                      // L
                    '',                      // M
                    '',                      // N  - CC Ingresos (OBLIGATORIO - completar)
                    '',                      // O  - CC Gastos/Inventario (OBLIGATORIO - completar)
                    '',                      // P  - CC Costo de Venta (OBLIGATORIO - completar)
                    '',                      // Q  - CodBarra1
                    '',                      // R  - CodBarra2
                    '',                      // S  - Marca
                    'NO',                    // T  - Avería
                    'NO',                    // U  - No Comisionable
                    '',                      // V  - % Variación Costo
                    '',                      // W  - Estilo
                    '',                      // X  - Talla
                    '',                      // Y  - Color
                    'SI',                    // Z  - MostrarEnVentas
                    'SI',                    // AA - MostrarEnCompras
                    '',                      // AB - CC Variación Costo
                    '',                      // AC - CC Variación Consumo
                    '',                      // AD - Precio Sugerido
                    '',                      // AE - Meta Inventario
                    '',                      // AF - Meta Cadena Producción
                    '',                      // AG - Código Ubicación Default
                    'NO',                    // AH - Requiere Lote
                    $fila->activo ? 'SI' : 'NO', // AI - Activo (OBLIGATORIO)
                    'NO',                    // AJ - Requiere Serie
                    '',                      // AK - Factor Ganancia
                    'NO',                    // AL - Permite Viñeta
                    'NO',                    // AM - Genera Viñeta
                    'NO',                    // AN - Agrupar por Cat.
                    '',                      // AO - Descripción Extendida
                    '',                      // AP - Centro de Costos
                    '',                      // AQ - Sub Centro de Costos
                    '',                      // AR - #Días Abastecerse
                    '',                      // AS - Código Partida Arancelaria
                    '',                      // AT - Cuenta Inventario en Proceso
                ];

                fputcsv($handle, $fila46);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** Cabecera de 46 columnas del formato VEN de BRILO. */
    private function venCabecera(): array
    {
        return [
            'Codigo Unico/De Barras',           // A  - obligatorio
            'Descripcion',                       // B  - obligatorio
            'Tipo',                              // C  - obligatorio  P=Inventariado
            'Modelo',                            // D
            'Precio de Lista (SIN IVA)',         // E
            'Exento',                            // F
            'Categoria Principal (Padre)',       // G
            'Categoria Principal (Hija)',        // H
            'Presentacion Base',                 // I
            'NomPrecioVenta2',                   // J
            'NomPrecioVenta3',                   // K
            'NomPrecioVenta4',                   // L
            'NomPrecioVenta5',                   // M
            'Cuenta Contable Ingresos',          // N
            'Cuenta Contable Gastos/Inventario', // O
            'Cuenta Contable Costo de Venta',   // P
            'CodBarra1',                         // Q
            'CodBarra2',                         // R
            'Marca',                             // S
            'Averia',                            // T
            'No Comisionable',                   // U
            '% Variacion Costo Neg Req Autoriz', // V
            'Estilo',                            // W
            'Talla',                             // X
            'Color',                             // Y
            'MostrarEnVentas',                   // Z
            'MostrarEnCompras',                  // AA
            'Cuenta Contable Variacion Costo',   // AB
            'Cuenta Contable Variacion Consumo', // AC
            'Precio Sugerido',                   // AD
            'Meta Inventario',                   // AE
            'Meta Cadena Produccion',            // AF
            'Codigo Ubicacion Default',          // AG
            'Requiere Lote',                     // AH
            'Activo',                            // AI - obligatorio
            'Requiere Serie',                    // AJ
            'Factor Ganancia',                   // AK
            'Permite Gen Viñeta Incentivo',      // AL
            'Genera Viñeta Incentivo',           // AM
            'Agrupar Por Cat. EnVenta',          // AN
            'Descripción Extendida para Cot',    // AO
            'Centro de Costos',                  // AP
            'Sub Centro de Costos',              // AQ
            '#Dias Abastecerse',                 // AR
            'Codigo Partida Arancelaria',        // AS
            'Cuenta Inventario en Proceso',      // AT
        ];
    }

    private function requireAdminRol(): void
    {
        $user  = auth()->user();
        $roles = $user ? $user->roles()->pluck('codigo')->toArray() : [];
        if (!array_intersect(['admin_compras', 'admin_recetas'], $roles)) {
            abort(403, 'No autorizado.');
        }
    }

    /** Formatea un número decimal sin ceros innecesarios. */
    private function formatNum(mixed $val): string
    {
        $n = (float) $val;
        if ($n == 0) return '';
        // Hasta 4 decimales, sin trailing zeros
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    /**
     * Convierte la unidad local (campo ri.unidad) al código de presentación de BRILO.
     * Los códigos de presentación deben coincidir exactamente con los configurados en BRILO.
     */
    private function unidadACodigoBrilo(string $unidad): string
    {
        return match (strtolower(trim($unidad))) {
            'oz', 'onza', 'onzas'                          => 'OZ001',
            'oz fl', 'fl oz', 'ozf',
                'onza fluida', 'onzas fluidas'             => 'OZF',    // ONZAS FLUIDAS — código distinto en BRILO
            'lb', 'libra', 'libras'                        => 'LB001',
            'kg', 'kilogramo', 'kilogramos'                => 'KG001',
            'lt', 'litro', 'litros'                        => 'LT001',
            'g', 'gr', 'gramo', 'gramos'                   => 'GR001',
            'porcion', 'porción'                           => 'UNIDAD', // PORCION no existe en BRILO → usar UNIDAD
            'u', 'und', 'unidad', 'unidades'               => 'UNIDAD',
            'galon', 'galón', 'gal'                        => 'GAL001',
            'botella'                                       => 'BOTELLA',
            'rebanada'                                      => 'REBANADA',
            default                                        => strtoupper($unidad),
        };
    }
}
