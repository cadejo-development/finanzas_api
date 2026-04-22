<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Agrega el distrito "San Salvador Este" que faltaba.
 *
 * La BD original tenía San Salvador dividido en solo 3 distritos (Norte, Centro, Sur),
 * con Ilopango, Soyapango, San Martín y Tonacatepeque incorrectamente en Norte/Centro.
 * Este fix: inserta "San Salvador Este" (codigo 0604) y reasigna los 4 municipios.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        // Obtener el departamento_id de San Salvador
        $deptoId = DB::connection('pgsql')
            ->table('geo_departamentos')
            ->where('codigo', '06')
            ->value('id');

        if (! $deptoId) {
            return;
        }

        // Evitar duplicado si ya existe
        $exists = DB::connection('pgsql')
            ->table('geo_distritos')
            ->where('departamento_id', $deptoId)
            ->where('codigo', '0604')
            ->exists();

        if ($exists) {
            return;
        }

        // 1. Insertar San Salvador Este
        $esteId = DB::connection('pgsql')->table('geo_distritos')->insertGetId([
            'departamento_id' => $deptoId,
            'codigo'          => '0604',
            'nombre'          => 'San Salvador Este',
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        // 2. Reasignar los 4 municipios del Este
        //    (Tonacatepeque, San Martín — venían de Norte;
        //     Ilopango, Soyapango — venían de Centro)
        DB::connection('pgsql')
            ->table('geo_municipios')
            ->whereIn('nombre', ['Tonacatepeque', 'San Martín', 'Ilopango', 'Soyapango'])
            ->where('departamento_id', $deptoId)
            ->update(['distrito_id' => $esteId, 'updated_at' => now()]);
    }

    public function down(): void
    {
        $deptoId = DB::connection('pgsql')
            ->table('geo_departamentos')
            ->where('codigo', '06')
            ->value('id');

        $esteId = DB::connection('pgsql')
            ->table('geo_distritos')
            ->where('departamento_id', $deptoId)
            ->where('codigo', '0604')
            ->value('id');

        if (! $esteId) {
            return;
        }

        // Devolver municipios al Centro (codigo 0602 en la estructura original)
        $centroId = DB::connection('pgsql')
            ->table('geo_distritos')
            ->where('departamento_id', $deptoId)
            ->where('codigo', '0602')
            ->value('id');

        DB::connection('pgsql')
            ->table('geo_municipios')
            ->whereIn('nombre', ['Tonacatepeque', 'San Martín', 'Ilopango', 'Soyapango'])
            ->where('departamento_id', $deptoId)
            ->update(['distrito_id' => $centroId, 'updated_at' => now()]);

        DB::connection('pgsql')
            ->table('geo_distritos')
            ->where('id', $esteId)
            ->delete();
    }
};
