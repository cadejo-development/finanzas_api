<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Inserta tipos de permiso y de incapacidad que no existen aún.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Días Cadejo (categoría propia; el backend valida las reglas de negocio)
        $existe = DB::connection('rrhh')
            ->table('tipos_permiso')
            ->whereRaw("lower(codigo) = 'dias_cadejo'")
            ->exists();

        if (!$existe) {
            DB::connection('rrhh')->table('tipos_permiso')->insert([
                'nombre'       => 'Días Cadejo',
                'codigo'       => 'dias_cadejo',
                'categoria'    => 'personal',
                'max_dias'     => 3,
                'permite_horas'=> false,
                'activo'       => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }

        // Consulta Médica (permiso especial con horas, max 4h)
        $existe2 = DB::connection('rrhh')
            ->table('tipos_permiso')
            ->whereRaw("lower(codigo) = 'consulta_medica'")
            ->exists();

        if (!$existe2) {
            DB::connection('rrhh')->table('tipos_permiso')->insert([
                'nombre'       => 'Consulta Médica',
                'codigo'       => 'consulta_medica',
                'categoria'    => 'especial',
                'max_dias'     => null,
                'permite_horas'=> true,
                'activo'       => true,
                'created_at'   => now(),
                'updated_at'   => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('rrhh')->table('tipos_permiso')
            ->whereIn('codigo', ['dias_cadejo', 'consulta_medica'])
            ->delete();
    }
};
