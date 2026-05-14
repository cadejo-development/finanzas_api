<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Exporta datos al formato CSV de importación BRILO ERP.
 *
 * Flujo de importación (orden obligatorio):
 *   Paso 1 — VEN Materias Primas   → crea materias primas nuevas en BRILO
 *   Paso 2 — VEN Recetas           → crea platos + sub-recetas en BRILO
 *   Paso 3 — INV Nivel 1           → ingredientes de sub-recetas simples
 *   Paso 4 — INV Nivel 2           → ingredientes de sub-recetas compuestas
 *   Paso 5 — INV Nivel 3           → ingredientes de platos
 *
 * Rutas:
 *   GET  /api/compras/export/brilo/productos              → VEN materias primas
 *   GET  /api/compras/export/brilo/recetas-ven            → VEN platos + sub-recetas
 *   GET  /api/compras/export/brilo/materiales-x-producto  → INV ingredientes
 *   POST /api/compras/export/brilo/resetear-modificados   → marcar como sincronizados
 */
class ExportBriloController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/compras/export/brilo/productos
    //
    // Exporta materias primas al formato VEN de BRILO.
    // Tipo PNI · Exento SI · Categoría padre MR · Mostrar en Compras SI
    // ──────────────────────────────────────────────────────────────────────────
    public function productos(Request $request): StreamedResponse
    {
        $this->requireAdminRol();

        $soloActivos     = (bool) $request->query('solo_activos', 1);
        $soloModificados = (bool) $request->query('solo_modificados', 1);

        $query = DB::connection('compras')
            ->table('productos as p')
            ->leftJoin('categorias as c', 'p.categoria_id', '=', 'c.id')
            ->select(['p.codigo', 'p.nombre', 'p.unidad', 'p.activo', 'c.nombre as categoria_nombre']);

        if ($soloModificados) $query->where('p.modificado_localmente', true);
        if ($soloActivos)     $query->where('p.activo', true);

        // Excluir productos cuyo código ya existe como receta en el sistema
        // (ej: PL... o SUBR... que se guardaron en la tabla productos por error)
        $query->whereNotIn('p.codigo', fn ($s) =>
            $s->select('codigo_origen')->from('recetas')
              ->whereNotNull('codigo_origen')->where('codigo_origen', '!=', '')
        );

        $filas = $query->orderBy('p.codigo')->get();

        $filename = 'VEN_Materias_Primas_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($filas) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->venCabecera());

            foreach ($filas as $fila) {
                fputcsv($handle, [
                    $fila->codigo,                          // A  Código
                    $fila->nombre,                          // B  Descripción
                    'PNI',                                  // C  Tipo
                    '',                                     // D  Modelo
                    '',                                     // E  Precio (sin precio para MP)
                    'SI',                                   // F  Exento
                    'MR',                                   // G  Categoría Padre (Materia Prima Restaurante)
                    '',                                     // H  Categoría Hija
                    $this->unidadACodigoBrilo($fila->unidad ?? ''), // I  Presentación Base
                    '', '', '', '',                         // J-M Precios venta 2-5
                    '', '', '',                             // N-P Cuentas contables (manual)
                    '', '',                                 // Q-R CodBarra
                    '',                                     // S  Marca
                    '',                                     // T  Avería (vacío)
                    '',                                     // U  No Comisionable (vacío)
                    '',                                     // V  % Variación Costo
                    '', '', '',                             // W-Y Estilo, Talla, Color
                    'NO',                                   // Z  MostrarEnVentas
                    'SI',                                   // AA MostrarEnCompras
                    '', '',                                 // AB-AC CC Variación
                    '',                                     // AD Precio Sugerido
                    '', '',                                 // AE-AF Meta
                    '',                                     // AG Ubicación
                    '',                                     // AH Requiere Lote (vacío)
                    'SI',                                   // AI Activo
                    '', '', '', '', '',                     // AJ-AN (vacío)
                    '',                                     // AO Descripción Extendida
                    '', '',                                 // AP-AQ Centro de Costos
                    '', '', '',                             // AR-AT
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/compras/export/brilo/recetas-ven
    //
    // Exporta platos + sub-recetas (todos los niveles) al formato VEN de BRILO.
    // Deben existir en BRILO ANTES de importar los archivos INV.
    // Sub-recetas van primero (nivel 1 → nivel 2), luego los platos.
    //
    // Parámetros query:
    //   solo_con_codigo  = 1 (default) → solo recetas con codigo_origen
    //   solo_modificados = 1 (default) → solo con modificado_localmente = true
    // ──────────────────────────────────────────────────────────────────────────
    public function recetasVen(Request $request): StreamedResponse
    {
        $this->requireAdminRol();

        $soloConCodigo   = (bool) $request->query('solo_con_codigo', 1);
        $soloModificados = (bool) $request->query('solo_modificados', 1);

        $base = DB::connection('compras')
            ->table('recetas as r')
            ->leftJoin('receta_categorias as rc', 'r.categoria_id', '=', 'rc.id')
            ->where('r.activa', true)
            ->select(['r.id', 'r.codigo_origen', 'r.nombre', 'r.tipo_receta', 'r.rendimiento_unidad', 'r.precio', 'rc.nombre as categoria_nombre']);

        if ($soloModificados) $base->where('r.modificado_localmente', true);
        if ($soloConCodigo)   $base->whereNotNull('r.codigo_origen')->where('r.codigo_origen', '!=', '');

        // Excluir CP y MR — son materias primas, no recetas/sub-recetas
        $base->where('r.codigo_origen', 'not like', 'CP%')
             ->where('r.codigo_origen', 'not like', 'MR%');

        $filas = $base->orderByRaw("
            CASE WHEN r.tipo_receta = 'sub_receta' THEN 0 ELSE 1 END,
            r.codigo_origen
        ")->get();

        // Ids de sub-recetas compuestas (contienen otras sub-recetas)
        $idsCompuestas = DB::connection('compras')
            ->table('receta_ingredientes')
            ->whereNotNull('sub_receta_id')
            ->pluck('receta_id')
            ->flip();

        $filename = 'VEN_Recetas_' . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($filas, $idsCompuestas) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");
            fputcsv($handle, $this->venCabecera());

            foreach ($filas as $fila) {
                // Sub-recetas siempre usan TANDA (UNID0029) como Presentación Base en Brilo
                $unidadBrilo = $fila->tipo_receta === 'sub_receta'
                    ? 'UNID0029'
                    : $this->unidadACodigoBrilo($fila->rendimiento_unidad ?? 'u');
                $precio      = (float) ($fila->precio ?? 0) > 0 ? $this->formatNum($fila->precio) : '';

                fputcsv($handle, [
                    $fila->codigo_origen ?? '',             // A  Código
                    $fila->nombre,                          // B  Descripción
                    'PNI',                                  // C  Tipo
                    '',                                     // D  Modelo
                    $precio,                                // E  Precio de Lista
                    'SI',                                   // F  Exento
                    '',                                     // G  Categoría Padre (completar manualmente)
                    $fila->categoria_nombre ?? '',          // H  Categoría Hija (categoría del plato)
                    $unidadBrilo,                           // I  Presentación Base
                    '', '', '', '',                         // J-M Precios venta 2-5
                    '', '', '',                             // N-P Cuentas contables (completar manualmente)
                    '', '',                                 // Q-R CodBarra
                    '',                                     // S  Marca
                    '',                                     // T  Avería (vacío)
                    '',                                     // U  No Comisionable (vacío)
                    '',                                     // V  % Variación Costo
                    '', '', '',                             // W-Y Estilo, Talla, Color
                    'NO',                                   // Z  MostrarEnVentas
                    'NO',                                   // AA MostrarEnCompras
                    '', '',                                 // AB-AC CC Variación
                    '',                                     // AD Precio Sugerido
                    '', '',                                 // AE-AF Meta
                    '',                                     // AG Ubicación
                    '',                                     // AH Requiere Lote (vacío)
                    'SI',                                   // AI Activo
                    '', '', '', '', '',                     // AJ-AN (vacío)
                    '',                                     // AO Descripción Extendida
                    '', '',                                 // AP-AQ Centro de Costos
                    '', '', '',                             // AR-AT
                ]);
            }

            fclose($handle);
        }, $filename, [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // GET /api/compras/export/brilo/materiales-x-producto
    //
    // Exporta ingredientes de recetas al formato INV de BRILO.
    //
    // nivel=1 → sub-recetas simples (solo materias primas como ingredientes)
    // nivel=2 → sub-recetas compuestas (incluyen otras sub-recetas)
    // nivel=3 → platos
    //
    // Regla: si el código de ingrediente empieza con CP → Detiene Explotación = SI
    // ──────────────────────────────────────────────────────────────────────────
    public function materialesXProducto(Request $request): StreamedResponse
    {
        $this->requireAdminRol();

        $nivel            = $request->query('nivel') ? (int) $request->query('nivel') : null;
        $tipoReceta       = $request->query('tipo_receta');
        $soloConCodigo    = (bool) $request->query('solo_con_codigo', 1);
        $estadoId         = $request->query('estado_id') ? (int) $request->query('estado_id') : null;
        $soloModificados  = (bool) $request->query('solo_modificados', 1);

        $query = DB::connection('compras')
            ->table('receta_ingredientes as ri')
            ->join('recetas as r', 'ri.receta_id', '=', 'r.id')
            ->leftJoin('productos as p',  'ri.producto_id',  '=', 'p.id')
            ->leftJoin('recetas as sr',   'ri.sub_receta_id', '=', 'sr.id')
            ->where('r.activa', true)
            ->select([
                'r.codigo_origen as receta_codigo',
                'r.tipo_receta',
                'p.codigo as prod_codigo',
                'p.unidad as prod_unidad',
                'sr.codigo_origen as sub_codigo',
                'ri.cantidad_por_plato',
                'ri.unidad',
            ]);

        if ($soloModificados) $query->where('r.modificado_localmente', true);
        if ($soloConCodigo)   $query->whereNotNull('r.codigo_origen')->where('r.codigo_origen', '!=', '');

        if ($nivel === 1) {
            $query->where('r.tipo_receta', 'sub_receta')
                  // Excluir sub-recetas que tienen otras sub-recetas via sub_receta_id
                  ->whereNotIn('r.id', fn ($s) =>
                      $s->select('receta_id')->from('receta_ingredientes')->whereNotNull('sub_receta_id')
                  )
                  // Excluir sub-recetas que tienen ingredientes PL/SUBR guardados como producto_id
                  // (ingrediente es una receta aunque esté referenciado como producto)
                  ->whereNotIn('r.id', fn ($s) =>
                      $s->select('ri2.receta_id')
                        ->from('receta_ingredientes as ri2')
                        ->join('productos as p2', 'ri2.producto_id', '=', 'p2.id')
                        ->whereIn('p2.codigo', fn ($sq) =>
                            $sq->select('codigo_origen')->from('recetas')
                              ->whereNotNull('codigo_origen')->where('codigo_origen', '!=', '')
                        )
                  );
        } elseif ($nivel === 2) {
            $query->where('r.tipo_receta', 'sub_receta')
                  ->whereIn('r.id', fn ($s) =>
                      $s->select('receta_id')->from('receta_ingredientes')->whereNotNull('sub_receta_id')
                  );
        } elseif ($nivel === 3) {
            $query->where(fn ($q) => $q->where('r.tipo_receta', 'plato')->orWhereNull('r.tipo_receta'))
                  ->whereRaw("lower(coalesce(r.tipo_receta,'')) NOT LIKE '%sub%receta%'");
        } elseif ($tipoReceta) {
            $query->where('r.tipo_receta', $tipoReceta);
        }

        if ($estadoId) $query->where('r.estado_id', $estadoId);

        $filas = $query->orderBy('r.codigo_origen')->orderBy('ri.id')->get();

        $nivelSufijo = match ($nivel) {
            1 => '_Nivel1_SubRecetas_Simples_',
            2 => '_Nivel2_SubRecetas_Compuestas_',
            3 => '_Nivel3_Platos_',
            default => '_',
        };
        $filename = 'INV_Materiales_X_Producto' . $nivelSufijo . now()->format('Ymd_His') . '.csv';

        return response()->streamDownload(function () use ($filas) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

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
                $esSub          = !is_null($fila->sub_codigo);
                $codIngrediente = $fila->sub_codigo ?? $fila->prod_codigo ?? '';
                if (!$codIngrediente) continue;

                // Detiene Explotación = SI si el código empieza con CP
                $detieneExp = str_starts_with(strtoupper(trim($codIngrediente)), 'CP') ? 'SI' : '';

                if ($esSub) {
                    $codPres = $this->unidadACodigoBrilo($fila->unidad ?? '');
                    fputcsv($handle, [
                        $fila->receta_codigo ?? '',                   // A
                        $codIngrediente,                              // B
                        '',                                           // C (vacío para sub-recetas)
                        'SI',                                         // D
                        $detieneExp,                                  // E
                        '',                                           // F
                        $codPres,                                     // G
                        $this->formatNum($fila->cantidad_por_plato),  // H
                    ]);
                } else {
                    // Comparar unidad de la receta vs unidad base del producto (por código Brilo)
                    // Si difieren, la cantidad va en Presentación (col G+H), no en Cantidad MP (col C)
                    $briloReceta = $this->unidadACodigoBrilo($fila->unidad ?? '');
                    $briloProd   = $fila->prod_unidad
                        ? $this->unidadACodigoBrilo($fila->prod_unidad)
                        : $briloReceta; // sin unidad base, asumir que coincide
                    $mismaUnidad = ($briloReceta === $briloProd);

                    fputcsv($handle, [
                        $fila->receta_codigo ?? '',                                              // A
                        $codIngrediente,                                                         // B
                        $mismaUnidad ? $this->formatNum($fila->cantidad_por_plato) : '',         // C
                        'SI',                                                                    // D
                        $detieneExp,                                                             // E
                        '',                                                                      // F
                        $mismaUnidad ? '' : $briloReceta,                                        // G
                        $mismaUnidad ? '' : $this->formatNum($fila->cantidad_por_plato),         // H
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
    // POST /api/compras/export/brilo/resetear-modificados
    // ──────────────────────────────────────────────────────────────────────────
    public function resetearModificados(Request $request)
    {
        $this->requireAdminRol();

        $tipo  = $request->input('tipo');
        $nivel = $request->input('nivel') ? (int) $request->input('nivel') : null;

        $count = 0;

        if ($tipo === 'productos') {
            $count = DB::connection('compras')->table('productos')
                ->where('modificado_localmente', true)
                ->update(['modificado_localmente' => false, 'updated_at' => now()]);

        } elseif ($tipo === 'recetas') {
            $query = DB::connection('compras')->table('recetas')
                ->where('activa', true)
                ->where('modificado_localmente', true);

            if ($nivel === 1) {
                $query->where('tipo_receta', 'sub_receta')
                      ->whereNotIn('id', fn ($s) =>
                          $s->select('receta_id')->from('receta_ingredientes')->whereNotNull('sub_receta_id')
                      );
            } elseif ($nivel === 2) {
                $query->where('tipo_receta', 'sub_receta')
                      ->whereIn('id', fn ($s) =>
                          $s->select('receta_id')->from('receta_ingredientes')->whereNotNull('sub_receta_id')
                      );
            } elseif ($nivel === 3) {
                $query->where(fn ($q) => $q->where('tipo_receta', 'plato')->orWhereNull('tipo_receta'));
            }

            $count = $query->update(['modificado_localmente' => false, 'updated_at' => now()]);

        // Legacy: mantener compatibilidad con llamadas antiguas
        } elseif ($tipo === 'platos') {
            $count = DB::connection('compras')->table('recetas')
                ->where('activa', true)->where('modificado_localmente', true)
                ->where(fn ($q) => $q->where('tipo_receta', 'plato')->orWhereNull('tipo_receta'))
                ->whereRaw("lower(coalesce(tipo_receta,'')) NOT LIKE '%sub%receta%'")
                ->update(['modificado_localmente' => false, 'updated_at' => now()]);

        } elseif ($tipo === 'sub_recetas') {
            $query = DB::connection('compras')->table('recetas')
                ->where('activa', true)->where('tipo_receta', 'sub_receta')->where('modificado_localmente', true);
            if ($nivel === 1) {
                $query->whereNotIn('id', fn ($s) =>
                    $s->select('receta_id')->from('receta_ingredientes')->whereNotNull('sub_receta_id')
                );
            } elseif ($nivel === 2) {
                $query->whereIn('id', fn ($s) =>
                    $s->select('receta_id')->from('receta_ingredientes')->whereNotNull('sub_receta_id')
                );
            }
            $count = $query->update(['modificado_localmente' => false, 'updated_at' => now()]);

        } else {
            return response()->json(['error' => 'Tipo inválido.'], 422);
        }

        return response()->json(['ok' => true, 'actualizados' => $count]);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /** Cabecera de 46 columnas del formato VEN de BRILO. */
    private function venCabecera(): array
    {
        return [
            'Codigo Unico/De Barras',           // A
            'Descripcion',                       // B
            'Tipo',                              // C
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
            'Cuenta Contable Costo de Venta',    // P
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
            'Activo',                            // AI
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
        $user  = \Illuminate\Support\Facades\Auth::user();
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
        return rtrim(rtrim(number_format($n, 4, '.', ''), '0'), '.');
    }

    /**
     * Convierte unidad local al código exacto de Unidades en BRILO (tabla olComun.dbo.Unidades).
     */
    private function unidadACodigoBrilo(string $unidad): string
    {
        return match (strtolower(trim($unidad))) {
            'oz', 'onza', 'onzas'                                       => 'OZ001',
            'oz fl', 'fl oz', 'ozf', 'onza fluida', 'onzas fluidas'    => 'OZF',
            'lb', 'libra', 'libras'                                     => 'C_LIBRA',
            'kg', 'kilogramo', 'kilogramos'                             => 'C_KG',
            'lt', 'litro', 'litros'                                     => 'C_LITRO',
            'ml', 'mililitros', 'mililitro'                             => 'C_LITRO', // convertir en BRILO
            'u', 'und', 'unidad', 'unidades'                           => 'UNID0005',
            'porcion', 'porción'                                        => 'UNID0013',
            'caja'                                                      => 'CAJ001',
            'paquete'                                                   => 'PAQU010',
            'galon', 'galón', 'gal'                                     => 'C_GALON',
            'botella', 'botella 1.75lt'                                 => 'BOTE001',
            'barril'                                                    => 'UNID0016',
            'bolsa 2kg', 'bolsa 1kg'                                   => 'UNID0009',
            'bolsa 5lb'                                                 => 'C_BOLSA',
            'bolsa 20lb'                                                => 'B-04',
            'rebanada'                                                  => 'UNID0021',
            'g', 'gr', 'gramo', 'gramos'                                => 'UNID0005',
            'tanda'                                                     => 'UNID0029',
            default                                                     => strtoupper(trim($unidad)),
        };
    }
}
