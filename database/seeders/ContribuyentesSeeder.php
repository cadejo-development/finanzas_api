<?php

namespace Database\Seeders;

use App\Models\Contribuyente;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class ContribuyentesSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 0 = No inscrito en IVA (factura consumidor final, sin NRC)
        // 1 = Contribuyente inscrito en IVA (emite CCF, tiene NIT + NRC)
        // 2 = Gran Contribuyente (régimen especial DGII)
        $tipos = [
            ['codigo' => 'no_inscrito',        'nombre' => 'No Inscrito / Factura Consumidor Final'],
            ['codigo' => 'contribuyente',       'nombre' => 'Contribuyente Inscrito en IVA'],
            ['codigo' => 'gran_contribuyente',  'nombre' => 'Gran Contribuyente'],
        ];

        foreach ($tipos as $t) {
            Contribuyente::updateOrCreate(
                ['codigo' => $t['codigo']],
                array_merge($t, ['activo' => true, 'aud_usuario' => 'seed', 'created_at' => now(), 'updated_at' => now()])
            );
        }
    }
}
