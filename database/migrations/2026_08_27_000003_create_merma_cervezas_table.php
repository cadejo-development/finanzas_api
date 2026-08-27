<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('merma_cervezas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre',    100);
            $table->string('estilo',    100)->nullable();
            $table->string('color_hex', 10)->default('#888888');
            // activo / temporada / inactivo
            $table->string('estado', 20)->default('activo');
            $table->date('vigencia_desde')->nullable();
            $table->date('vigencia_hasta')->nullable();
            $table->unsignedSmallInteger('orden')->default(0);
            $table->timestamps();
        });

        // Catálogo inicial del mockup
        $cervezas = [
            ['WAPA',          'Golden Ale',      '#e8a22b', 'activo', 1],
            ['Roja',          'Amber Ale',       '#b5462a', 'activo', 2],
            ['Negra',         'Stout',           '#6b4436', 'activo', 3],
            ['Mera Belga',    'Belgian Dubbel',  '#d99a2b', 'activo', 4],
            ['Hija de Pooh',  'Hazy IPA',        '#e0871f', 'activo', 5],
            ['Nacional',      'Lager',           '#e8c56b', 'activo', 6],
            ['Suegra',        'Imperial Stout',  '#c77a3a', 'activo', 7],
            ['Skyhopper',     'IPA',             '#8fa63e', 'activo', 8],
            ['Otros barriles','Estilos varios',  '#6b7480', 'activo', 9],
        ];

        foreach ($cervezas as [$nombre, $estilo, $color, $estado, $orden]) {
            DB::connection('compras')->table('merma_cervezas')->insert([
                'nombre'     => $nombre,
                'estilo'     => $estilo,
                'color_hex'  => $color,
                'estado'     => $estado,
                'orden'      => $orden,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_cervezas');
    }
};
