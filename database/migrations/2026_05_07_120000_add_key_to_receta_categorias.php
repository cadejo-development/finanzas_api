<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->table('receta_categorias', function (Blueprint $table) {
            $table->string('key', 10)->nullable()->unique()->after('nombre');
        });

        // Mapeo de categorías conocidas a sus keys de BRILO
        $mapa = [
            'Platos Fuertes'         => 'PL-01',
            'Platos Malcriadas AE2'  => 'PL-05',
            'Platos Postres'         => 'PL-07',
            'Platos Empleados'       => 'PL-08',
            'Platos Entradas'        => 'PL-12',
            'Platos Infantiles'      => 'PL-13',
            'Platos Extras Clientes' => 'PL-18',
            'Platos Sub-Recetas'     => 'PL-20',
            'Platos Desayunos'       => 'PL-09',
            'Bebidas con Alcohol'    => 'BR-01',
            'Bebidas sin Alcohol'    => 'BR-05',
            'Bebidas Malcriadas AE2 s/a' => 'BR-06',
        ];

        foreach ($mapa as $nombre => $key) {
            DB::connection('compras')
                ->table('receta_categorias')
                ->where('nombre', $nombre)
                ->update(['key' => $key]);
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->table('receta_categorias', function (Blueprint $table) {
            $table->dropColumn('key');
        });
    }
};
