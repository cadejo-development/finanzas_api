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
            $table->integer('orden')->default(99)->after('key');
        });

        $rc  = fn () => DB::connection('compras')->table('receta_categorias');
        $now = now();

        // Corregir keys mal asignadas en migración anterior
        $rc()->where('nombre', 'Platos Desayunos')->update(['key' => 'PL-14']);   // era PL-09
        $rc()->where('nombre', 'Platos Infantiles')->update(['key' => null]);      // PL-13 no es Infantiles
        $rc()->where('nombre', 'Platos Eventos')->whereNull('key')->update(['key' => 'PL-10']);

        // Liberar PL-13 antes de asignarlo a Mascotas
        // (la línea de Infantiles arriba ya lo libera)

        // Insertar Platos Mascotas en receta_categorias
        $rc()->insertOrIgnore([
            'nombre'     => 'Platos Mascotas',
            'key'        => 'PL-13',
            'activa'     => true,
            'orden'      => 7,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Insertar Platos Mascotas en categorias (necesario para join en VEN col G)
        DB::connection('compras')->table('categorias')->insertOrIgnore([
            'key'         => 'PL-13',
            'nombre'      => 'Platos Mascotas',
            'activo'      => true,
            'aud_usuario' => 'sistema-migration',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);

        // Asignar orden por key
        $ordenPorKey = [
            'PL-01' =>  1,
            'PL-05' =>  2,
            'PL-07' =>  3,
            'PL-08' =>  4,
            'PL-10' =>  5,
            'PL-12' =>  6,
            'PL-13' =>  7,
            'PL-14' =>  8,
            'PL-18' =>  9,
            'PL-20' => 10,
            'BR-01' => 11,
            'BR-06' => 12,
            'BR-05' => 13,
        ];

        foreach ($ordenPorKey as $key => $orden) {
            $rc()->where('key', $key)->update(['orden' => $orden]);
        }
        // Entradas sin key (Platos Infantiles, etc.) quedan con orden=99 por defecto
    }

    public function down(): void
    {
        Schema::connection('compras')->table('receta_categorias', function (Blueprint $table) {
            $table->dropColumn('orden');
        });

        DB::connection('compras')->table('receta_categorias')
            ->where('nombre', 'Platos Desayunos')->update(['key' => 'PL-09']);
        DB::connection('compras')->table('receta_categorias')
            ->where('nombre', 'Platos Infantiles')->update(['key' => 'PL-13']);
        DB::connection('compras')->table('receta_categorias')
            ->where('nombre', 'Platos Mascotas')->delete();
        DB::connection('compras')->table('categorias')
            ->where('key', 'PL-13')->where('nombre', 'Platos Mascotas')->delete();
    }
};
