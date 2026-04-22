<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige dos errores de datos en la tabla geo_municipios:
 *
 * 1) La Libertad: El municipio id=81 estaba nombrado "Rosario de Mora"
 *    (que es un municipio de San Salvador) — su nombre correcto es "Colón".
 *
 * 2) Morazán: "Guatajiagua" aparecía duplicada en dos distritos distintos
 *    (San Francisco Gotera y Corinto). El correcto es el de San Francisco Gotera.
 *    Se elimina la entrada extra del distrito Corinto.
 *
 * Resultado: 262 municipios correctos (total oficial de El Salvador).
 *
 * Nota sobre San Salvador Oeste: NO existe como distrito de San Salvador porque
 * los 19 municipios del departamento quedan correctamente en Norte/Centro/Sur/Este.
 * Los municipios del área metropolitana occidental (Santa Tecla, Antiguo Cuscatlán,
 * Zaragoza, etc.) pertenecen al departamento de La Libertad y están bajo ese depto.
 */
return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        // 1. Renombrar "Rosario de Mora" (La Libertad) → "Colón"
        $affected = DB::connection('pgsql')
            ->table('geo_municipios')
            ->where('id', 81)
            ->where('nombre', 'Rosario de Mora')
            ->update(['nombre' => 'Colón', 'updated_at' => now()]);

        // Fallback por nombre + departamento si el ID cambia en otro ambiente
        if ($affected === 0) {
            $laLibertadId = DB::connection('pgsql')
                ->table('geo_departamentos')
                ->where('codigo', '05')
                ->value('id');

            if ($laLibertadId) {
                DB::connection('pgsql')
                    ->table('geo_municipios')
                    ->where('departamento_id', $laLibertadId)
                    ->where('nombre', 'Rosario de Mora')
                    ->update(['nombre' => 'Colón', 'updated_at' => now()]);
            }
        }

        // 2. Eliminar Guatajiagua duplicada (la del distrito Corinto es la extra)
        // El correcto es el de San Francisco Gotera. Eliminamos el de Corinto
        // buscando por nombre + distrito código '1302'
        $morazanId = DB::connection('pgsql')
            ->table('geo_departamentos')
            ->where('codigo', '13')
            ->value('id');

        if ($morazanId) {
            $corintoDistId = DB::connection('pgsql')
                ->table('geo_distritos')
                ->where('departamento_id', $morazanId)
                ->where('codigo', '1302')
                ->value('id');

            if ($corintoDistId) {
                DB::connection('pgsql')
                    ->table('geo_municipios')
                    ->where('nombre', 'Guatajiagua')
                    ->where('departamento_id', $morazanId)
                    ->where('distrito_id', $corintoDistId)
                    ->delete();
            }
        }
    }

    public function down(): void
    {
        // Revertir renombrado Colón → Rosario de Mora
        DB::connection('pgsql')
            ->table('geo_municipios')
            ->where('id', 81)
            ->where('nombre', 'Colón')
            ->update(['nombre' => 'Rosario de Mora', 'updated_at' => now()]);

        // Revertir eliminación de Guatajiagua duplicada
        $morazanId = DB::connection('pgsql')
            ->table('geo_departamentos')
            ->where('codigo', '13')
            ->value('id');

        $corintoDistId = DB::connection('pgsql')
            ->table('geo_distritos')
            ->where('departamento_id', $morazanId)
            ->where('codigo', '1302')
            ->value('id');

        if ($morazanId && $corintoDistId) {
            DB::connection('pgsql')->table('geo_municipios')->insert([
                'departamento_id' => $morazanId,
                'distrito_id'     => $corintoDistId,
                'nombre'          => 'Guatajiagua',
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);
        }
    }
};
