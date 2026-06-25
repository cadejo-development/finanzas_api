<?php

/**
 * BrewIngredientesSeeder
 * 
 * Sincroniza maltas, lúpulos y cervezas desde Brilo (SQL Server / VPN)
 * hacia la tabla brew_ingredientes en el RDS local (compras).
 * 
 * EJECUTAR SOLO CON VPN ACTIVA:
 *   php artisan db:seed --class=BrewIngredientesSeeder
 * 
 * Para re-sincronizar (limpia y recarga):
 *   php artisan db:seed --class=BrewIngredientesSeeder --force
 */

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class BrewIngredientesSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('Conectando a Brilo SQL Server...');

        $maltas   = $this->fetchBrilo('malta');
        $lupulos  = $this->fetchBrilo('lupulo');
        $cervezas = $this->fetchBrilo('cerveza');

        $this->command->info("Jalados: {$maltas} maltas, {$lupulos} lúpulos, {$cervezas} cervezas.");
        $this->command->info('Sincronización completa.');
    }

    private function fetchBrilo(string $tipo): int
    {
        $where = match ($tipo) {
            'malta' => "
                LOWER(proNombre) LIKE '%malta%'
             OR LOWER(proNombre) LIKE '%malt%'
             OR LOWER(proNombre) LIKE '%grain%'
             OR LOWER(proNombre) LIKE '%grano%'
             OR LOWER(proNombre) LIKE '%trigo%'
             OR LOWER(proNombre) LIKE '%cebada%'
             OR LOWER(proNombre) LIKE '%avena%'
             OR LOWER(proNombre) LIKE '%wheat%'
             OR LOWER(proNombre) LIKE '%barley%'
             OR LOWER(proNombre) LIKE '%centeno%'
             OR LOWER(proNombre) LIKE '%pilsen%'
             OR LOWER(proNombre) LIKE '%caramel%'
             OR LOWER(proNombre) LIKE '%crystal%'
             OR LOWER(proNombre) LIKE '%black%'
             OR LOWER(proNombre) LIKE '%roast%'
             OR LOWER(proNombre) LIKE '%chocolate%'
             OR LOWER(proNombre) LIKE '%munich%'
             OR LOWER(proNombre) LIKE '%vienna%'
             OR LOWER(proNombre) LIKE '%pale%'",
            'lupulo' => "
                LOWER(proNombre) LIKE '%lupulo%'
             OR LOWER(proNombre) LIKE '%l%pulo%'
             OR LOWER(proNombre) LIKE '%hop%'
             OR LOWER(proNombre) LIKE '%cascade%'
             OR LOWER(proNombre) LIKE '%centennial%'
             OR LOWER(proNombre) LIKE '%chinook%'
             OR LOWER(proNombre) LIKE '%citra%'
             OR LOWER(proNombre) LIKE '%simcoe%'
             OR LOWER(proNombre) LIKE '%galaxy%'
             OR LOWER(proNombre) LIKE '%mosaic%'
             OR LOWER(proNombre) LIKE '%saaz%'
             OR LOWER(proNombre) LIKE '%hallertau%'
             OR LOWER(proNombre) LIKE '%fuggle%'
             OR LOWER(proNombre) LIKE '%amarillo%'
             OR LOWER(proNombre) LIKE '%equinox%'
             OR LOWER(proNombre) LIKE '%el dorado%'
             OR LOWER(proNombre) LIKE '%magnum%'",
            'cerveza' => "
                LOWER(proNombre) LIKE '%cadejo%'
             OR LOWER(proNombre) LIKE '%cerveza%'
             OR LOWER(proNombre) LIKE '%lager%'
             OR LOWER(proNombre) LIKE '%ale%'
             OR LOWER(proNombre) LIKE '%stout%'
             OR LOWER(proNombre) LIKE '%porter%'
             OR LOWER(proNombre) LIKE '%ipa%'
             OR LOWER(proNombre) LIKE '%pilsner%'
             OR LOWER(proNombre) LIKE '%wheat%'
             OR LOWER(proNombre) LIKE '%tripel%'
             OR LOWER(proNombre) LIKE '%dubbel%'
             OR LOWER(proNombre) LIKE '%saison%'
             OR LOWER(proNombre) LIKE '%sour%'",
            default => '1=0',
        };

        $rows = DB::connection('origen')->select("
            SELECT LTRIM(RTRIM(proCodigo)) AS codigo,
                   LTRIM(RTRIM(proNombre))  AS nombre
            FROM   olComun.dbo.Productos WITH (NOLOCK)
            WHERE  proActivo = 1
              AND  ({$where})
              AND  LOWER(proNombre) NOT LIKE '%camisa%'
              AND  LOWER(proNombre) NOT LIKE '%camiseta%'
              AND  LOWER(proNombre) NOT LIKE '%playera%'
              AND  LOWER(proNombre) NOT LIKE '%polo%'
              AND  LOWER(proNombre) NOT LIKE '%gorra%'
              AND  LOWER(proNombre) NOT LIKE '%vaso%'
              AND  LOWER(proNombre) NOT LIKE '%bot. %'
              AND  LOWER(proNombre) NOT LIKE '%botella%'
              AND  LOWER(proNombre) NOT LIKE '%caja%'
              AND  LOWER(proNombre) NOT LIKE '%skyhopper%'
              AND  LOWER(proNombre) NOT LIKE '%souvenir%'
              AND  LOWER(proNombre) NOT LIKE '%merch%'
            ORDER BY proNombre
        ");

        if (empty($rows)) {
            $this->command->warn("  ⚠ No se encontraron registros para tipo={$tipo} en Brilo.");
            return 0;
        }

        // Borrar los del mismo tipo y reinsertar (upsert por nombre)
        DB::connection('compras')
            ->table('brew_ingredientes')
            ->where('tipo', $tipo)
            ->delete();

        $now = now();
        $insert = array_map(fn($r) => [
            'tipo'       => $tipo,
            'codigo'     => $r->codigo ?? '',
            'nombre'     => $r->nombre ?? '',
            'activo'     => true,
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        // Insert en chunks de 200 para evitar límites
        foreach (array_chunk($insert, 200) as $chunk) {
            DB::connection('compras')->table('brew_ingredientes')->insert($chunk);
        }

        $this->command->info("  ✓ {$tipo}: " . count($rows) . " registros insertados.");
        return count($rows);
    }
}
