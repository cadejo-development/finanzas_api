<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan compras:sync-origen [--solo=productos|recetas] [--forzar]
 *
 * Sincroniza productos (materias primas) y recetas desde el SQL Server de origen
 * hacia la base compras_db, preservando los registros que el usuario ya modificó
 * localmente (modificado_localmente = true).
 *
 * Lógica por registro de SQL Server:
 *   - Si existe localmente CON modificado_localmente = true → se omite.
 *   - Si existe localmente con modificado_localmente = false → se sobreescribe.
 *   - Si no existe localmente → se inserta.
 *
 * --forzar  Ignora la bandera modificado_localmente y sobreescribe todo.
 *            Útil para forzar un reset completo cuando sea necesario.
 */
class SyncOrigenCommand extends Command
{
    protected $signature = 'compras:sync-origen
                            {--solo= : Limitar sync a "productos" o "recetas"}
                            {--forzar : Sobreescribir registros aunque estén modificados localmente}';

    protected $description = 'Sincroniza productos y recetas desde SQL Server, preservando modificaciones locales.';

    public function handle(): int
    {
        $solo   = $this->option('solo');
        $forzar = $this->option('forzar');

        if ($forzar) {
            $this->warn('Modo --forzar activo: se sobreescribirán TODOS los registros, incluyendo los modificados localmente.');
        }

        if (!$solo || $solo === 'productos') {
            $this->syncProductos($forzar);
        }

        if (!$solo || $solo === 'recetas') {
            $this->syncRecetas($forzar);
        }

        $this->info('Sync completado.');
        return 0;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // PRODUCTOS (materias primas)
    // ──────────────────────────────────────────────────────────────────────────
    private function syncProductos(bool $forzar): void
    {
        $this->info('Sincronizando productos...');

        // ── PASO 1: Leer desde SQL Server ─────────────────────────────────────
        // Ajusta la consulta a las tablas y columnas reales de tu SQL Server.
        $rows = DB::connection('origen')->select("
            SELECT
                pro.proCodigo       AS codigo_origen,
                pro.proDescripcion  AS nombre,
                pro.proUnidad       AS unidad,
                pro.proCosto        AS costo,
                pro.proPrecio       AS precio,
                pro.proCategoria    AS categoria_codigo,
                CASE WHEN pro.proActivo = 1 THEN 1 ELSE 0 END AS activo
            FROM dbo.Productos pro
            WHERE pro.proActivo = 1
            ORDER BY pro.proCodigo
        ");
        // TODO: ajusta el nombre de tabla/columnas según tu esquema SQL Server.

        if (empty($rows)) {
            $this->warn('  Sin registros en SQL Server para productos.');
            return;
        }

        $compras = DB::connection('compras');
        $now     = now();

        // Obtener IDs de categorías por key para mapear
        $catIds = $compras->table('categorias')->pluck('id', 'key');

        // Obtener el conjunto de codigo_origen que YA están modificados localmente
        $modificados = $forzar
            ? collect()
            : $compras->table('productos')
                ->where('modificado_localmente', true)
                ->whereNotNull('codigo_origen')
                ->pluck('codigo_origen')
                ->flip(); // usamos flip para O(1) lookup

        $insertados   = 0;
        $actualizados = 0;
        $omitidos     = 0;

        foreach ($rows as $row) {
            $codigoOrigen = (string) $row->codigo_origen;

            // Respetar modificaciones locales
            if (isset($modificados[$codigoOrigen])) {
                $omitidos++;
                continue;
            }

            // Mapear categoría (ajusta los keys según tus categorías reales)
            $categoriaKey = $this->mapearCategoria($row->categoria_codigo ?? '');
            $categoriaId  = $catIds[$categoriaKey] ?? $catIds['general'] ?? null;

            $datos = [
                'nombre'                => trim($row->nombre),
                'unidad'                => strtolower(trim($row->unidad ?? 'u')),
                'precio'                => (float) ($row->precio ?? 0),
                'costo'                 => (float) ($row->costo ?? 0),
                'activo'                => (bool) $row->activo,
                'categoria_id'          => $categoriaId,
                'aud_usuario'           => 'sync-origen',
                'modificado_localmente' => false,
                'updated_at'            => $now,
            ];

            $existente = $compras->table('productos')
                ->where('codigo_origen', $codigoOrigen)
                ->first();

            if ($existente) {
                $compras->table('productos')
                    ->where('codigo_origen', $codigoOrigen)
                    ->update($datos);
                $actualizados++;
            } else {
                $compras->table('productos')->insert(array_merge($datos, [
                    'codigo'        => $codigoOrigen,  // usar codigo_origen como codigo local
                    'codigo_origen' => $codigoOrigen,
                    'origen'        => 'restaurante',
                    'created_at'    => $now,
                ]));
                $insertados++;
            }
        }

        $this->line("  Productos → insertados: {$insertados}, actualizados: {$actualizados}, omitidos (modificados localmente): {$omitidos}");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // RECETAS (platos + sub-recetas)
    // ──────────────────────────────────────────────────────────────────────────
    private function syncRecetas(bool $forzar): void
    {
        $this->info('Sincronizando recetas...');

        // ── PASO 1: Leer cabeceras de recetas desde SQL Server ─────────────────
        // Ajusta la consulta a las tablas/columnas reales de tu SQL Server.
        $cabeceras = DB::connection('origen')->select("
            SELECT
                rec.recCodigo       AS codigo_origen,
                rec.recNombre       AS nombre,
                rec.recDescripcion  AS descripcion,
                rec.recTipo         AS tipo,
                rec.recPlatos       AS platos_semana,
                CASE WHEN rec.recActivo = 1 THEN 1 ELSE 0 END AS activa,
                CASE WHEN rec.recEsSubReceta = 1 THEN 'sub_receta' ELSE 'plato' END AS tipo_receta
            FROM dbo.Recetas rec
            WHERE rec.recActivo = 1
            ORDER BY rec.recCodigo
        ");
        // TODO: ajusta nombres de tabla/columnas.

        if (empty($cabeceras)) {
            $this->warn('  Sin registros en SQL Server para recetas.');
            return;
        }

        // ── PASO 2: Leer ingredientes de todas las recetas ─────────────────────
        $ingredientesRaw = DB::connection('origen')->select("
            SELECT
                ri.riRecetaCodigo   AS receta_codigo_origen,
                ri.riProductoCodigo AS producto_codigo_origen,
                ri.riCantidad       AS cantidad_por_plato,
                ri.riUnidad         AS unidad
            FROM dbo.RecetaIngredientes ri
            INNER JOIN dbo.Recetas rec ON rec.recCodigo = ri.riRecetaCodigo AND rec.recActivo = 1
            ORDER BY ri.riRecetaCodigo
        ");
        // TODO: ajusta nombres de tabla/columnas.

        // Agrupar ingredientes por receta_codigo_origen
        $ingredientesPorReceta = [];
        foreach ($ingredientesRaw as $ing) {
            $ingredientesPorReceta[(string) $ing->receta_codigo_origen][] = $ing;
        }

        $compras = DB::connection('compras');
        $now     = now();

        // Mapas de referencia
        $productosPorCodigo = $compras->table('productos')
            ->whereNotNull('codigo_origen')
            ->pluck('id', 'codigo_origen'); // [codigo_origen => id]

        // Para sub-recetas usadas como ingrediente: también buscar por codigo_origen en recetas
        $recetasPorCodigo = $compras->table('recetas')
            ->whereNotNull('codigo_origen')
            ->pluck('id', 'codigo_origen');

        // Conjunto de recetas modificadas localmente
        $modificadas = $forzar
            ? collect()
            : $compras->table('recetas')
                ->where('modificado_localmente', true)
                ->whereNotNull('codigo_origen')
                ->pluck('codigo_origen')
                ->flip();

        $insertadas   = 0;
        $actualizadas = 0;
        $omitidas     = 0;

        foreach ($cabeceras as $cab) {
            $codigoOrigen = (string) $cab->codigo_origen;

            // Respetar modificaciones locales — ni la cabecera ni los ingredientes
            if (isset($modificadas[$codigoOrigen])) {
                $omitidas++;
                continue;
            }

            $datosReceta = [
                'nombre'                => trim($cab->nombre),
                'descripcion'           => trim($cab->descripcion ?? ''),
                'tipo'                  => $cab->tipo ?? null,
                'tipo_receta'           => $cab->tipo_receta ?? 'plato',
                'platos_semana'         => (int) ($cab->platos_semana ?? 0),
                'activa'                => (bool) $cab->activa,
                'aud_usuario'           => 'sync-origen',
                'modificado_localmente' => false,
                'updated_at'            => $now,
            ];

            $existente = $compras->table('recetas')
                ->where('codigo_origen', $codigoOrigen)
                ->first();

            if ($existente) {
                $recetaId = $existente->id;
                $compras->table('recetas')
                    ->where('id', $recetaId)
                    ->update($datosReceta);
                $actualizadas++;
            } else {
                $recetaId = $compras->table('recetas')->insertGetId(array_merge($datosReceta, [
                    'codigo_origen' => $codigoOrigen,
                    'created_at'    => $now,
                ]));
                // Actualizar mapa para que ingredientes de sub-recetas posteriores lo encuentren
                $recetasPorCodigo[$codigoOrigen] = $recetaId;
                $insertadas++;
            }

            // ── Reemplazar ingredientes (solo si la receta NO fue modificada) ──
            $compras->table('receta_ingredientes')->where('receta_id', $recetaId)->delete();

            foreach ($ingredientesPorReceta[$codigoOrigen] ?? [] as $ing) {
                $productoId  = $productosPorCodigo[(string) $ing->producto_codigo_origen] ?? null;
                // Si el ingrediente es una sub-receta, buscarla también en recetas
                $subRecetaId = $recetasPorCodigo[(string) $ing->producto_codigo_origen] ?? null;

                if (!$productoId && !$subRecetaId) {
                    $this->warn("  Ingrediente '{$ing->producto_codigo_origen}' no encontrado para receta '{$codigoOrigen}' — omitido.");
                    continue;
                }

                $compras->table('receta_ingredientes')->insert([
                    'receta_id'          => $recetaId,
                    'producto_id'        => $productoId,
                    'sub_receta_id'      => $subRecetaId,
                    'cantidad_por_plato' => (float) $ing->cantidad_por_plato,
                    'unidad'             => strtolower(trim($ing->unidad ?? 'u')),
                    'aud_usuario'        => 'sync-origen',
                    'created_at'         => $now,
                    'updated_at'         => $now,
                ]);
            }
        }

        $this->line("  Recetas → insertadas: {$insertadas}, actualizadas: {$actualizadas}, omitidas (modificadas localmente): {$omitidas}");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Mapea el código/nombre de categoría de SQL Server al key local.
     * Ajusta este método según las categorías reales de tu sistema de origen.
     */
    private function mapearCategoria(string $categoriaOrigen): string
    {
        $mapa = [
            // 'valor en SQL Server' => 'key local en tabla categorias'
            'GENERAL'   => 'general',
            'CP'        => 'cp',
            'EMPAQUE'   => 'empaque',
            'PROMO'     => 'promo',
            'EXTRAS'    => 'extras',
        ];

        $upper = strtoupper(trim($categoriaOrigen));
        return $mapa[$upper] ?? 'general';
    }
}
