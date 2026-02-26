<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $seeders = [];
        // Solo ejecutar seeders core en la base central
        if (DB::connection()->getName() === 'pgsql') {
            $seeders[] = SucursalesSeeder::class;
            $seeders[] = CentrosCostoSeeder::class;
            $seeders[] = CategoriasSeeder::class;
        }
        // Seeders comunes a todas las bases
        $seeders = array_merge($seeders, [
            //ProductosSeeder::class,
            ProveedoresSeeder::class,
            ContribuyentesSeeder::class,
            FormasPagoSeeder::class,
            EstadosSolicitudPagoSeeder::class,
            //PedidosSeeder::class,
            //PresupuestosUnidadSeeder::class,
            SolicitudesPagoSeeder::class,
        ]);
        $this->call($seeders);
    }
}
