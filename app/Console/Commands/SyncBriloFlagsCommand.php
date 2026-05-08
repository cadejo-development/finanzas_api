<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * php artisan brew:sync-brilo-flags
 *
 * Lee TODOS los códigos de productos activos desde SQL Server (BRILO/olcomun),
 * los guarda en memoria, compara contra las recetas en compras_db y actualiza
 * el campo sincronizado_brilo:
 *   - true  → el código existe en BRILO (ya estaba allá)
 *   - false → el código NO existe en BRILO (plato nuevo, hay que exportarlo)
 *
 * Solo actualiza sincronizado_brilo. No toca modificado_localmente ni ningún
 * otro campo. No inserta ni elimina registros.
 */
class SyncBriloFlagsCommand extends Command
{
    protected $signature   = 'brew:sync-brilo-flags';
    protected $description = 'Marca sincronizado_brilo en recetas comparando contra códigos activos en BRILO (SQL Server).';

    public function handle(): int
    {
        // ── 1. Leer todos los códigos activos de SQL Server ──────────────────
        $this->info('Conectando a SQL Server (BRILO)...');

        try {
            $briloRows = DB::connection('origen')->select("
                SELECT proCodigo
                FROM   dbo.Productos
                WHERE  proActivo = 1
                  AND  proCodigo IS NOT NULL
                  AND  proCodigo <> ''
            ");
        } catch (\Throwable $e) {
            $this->error('Error al conectar con SQL Server: ' . $e->getMessage());
            return 1;
        }

        // Guardar en memoria como Set para lookup O(1)
        $codigosBrilo = collect($briloRows)
            ->pluck('proCodigo')
            ->map(fn($c) => trim((string) $c))
            ->filter()
            ->flip()  // convierte la colección en [codigo => indice] para O(1) lookup
            ->toArray();

        $totalBrilo = count($codigosBrilo);
        $this->info("  → {$totalBrilo} códigos activos leídos de BRILO.");

        // ── 2. Leer recetas (platos) de nuestra base ─────────────────────────
        $recetas = DB::connection('compras')
            ->table('recetas')
            ->whereNotNull('codigo_origen')
            ->where('codigo_origen', '<>', '')
            ->select('id', 'codigo_origen', 'sincronizado_brilo')
            ->get();

        $this->info("  → {$recetas->count()} recetas con código en nuestra base.");

        // ── 3. Comparar y actualizar solo sincronizado_brilo ─────────────────
        $marcadosTrue  = 0;
        $marcadosFalse = 0;
        $sinCambio     = 0;

        foreach ($recetas as $receta) {
            $codigo = trim((string) $receta->codigo_origen);
            $existeEnBrilo = array_key_exists($codigo, $codigosBrilo);

            $nuevoValor = $existeEnBrilo ? true : false;
            $valorActual = (bool) $receta->sincronizado_brilo;

            if ($nuevoValor === $valorActual) {
                $sinCambio++;
                continue;
            }

            DB::connection('compras')
                ->table('recetas')
                ->where('id', $receta->id)
                ->update(['sincronizado_brilo' => $nuevoValor]);

            if ($nuevoValor) {
                $marcadosTrue++;
            } else {
                $marcadosFalse++;
            }
        }

        // ── 4. Recetas sin código (no se pueden comparar) ────────────────────
        $sinCodigo = DB::connection('compras')
            ->table('recetas')
            ->where(function ($q) {
                $q->whereNull('codigo_origen')->orWhere('codigo_origen', '');
            })
            ->where(function ($q) {
                $q->where('tipo_receta', 'plato')->orWhereNull('tipo_receta');
            })
            ->count();

        // ── 5. Resumen ────────────────────────────────────────────────────────
        $this->newLine();
        $this->table(
            ['Estado', 'Cantidad'],
            [
                ['Marcados sincronizado_brilo = true  (ya en BRILO)',  $marcadosTrue],
                ['Marcados sincronizado_brilo = false (nuevos/no en BRILO)', $marcadosFalse],
                ['Sin cambio (ya tenían el valor correcto)',            $sinCambio],
                ['Platos sin código_origen (no comparables)',          $sinCodigo],
            ]
        );

        $this->info('Listo. Solo se actualizó sincronizado_brilo.');
        return 0;
    }
}
