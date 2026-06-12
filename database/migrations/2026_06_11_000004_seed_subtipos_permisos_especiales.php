<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        $tipos = [
            [
                'codigo'                 => 'maternidad',
                'nombre'                 => 'Licencia por Maternidad',
                'categoria'              => 'especial',
                'max_dias'               => null,
                'duracion_max_dias'      => 112,
                'permite_horas'          => false,
                'solo_dias_completos'    => true,
                'requiere_documento'     => true,
                'anticipacion_min_dias'  => null,
                'dentro_de_dias_evento'  => null,
                'activo'                 => true,
                'aud_usuario'            => 'system',
            ],
            [
                'codigo'                 => 'paternidad',
                'nombre'                 => 'Permiso por Paternidad',
                'categoria'              => 'especial',
                'max_dias'               => null,
                'duracion_max_dias'      => 3,
                'permite_horas'          => false,
                'solo_dias_completos'    => true,
                'requiere_documento'     => true,
                'anticipacion_min_dias'  => null,
                'dentro_de_dias_evento'  => 15,
                'activo'                 => true,
                'aud_usuario'            => 'system',
            ],
            [
                'codigo'                 => 'matrimonio',
                'nombre'                 => 'Permiso por Matrimonio',
                'categoria'              => 'especial',
                'max_dias'               => null,
                'duracion_max_dias'      => 3,
                'permite_horas'          => false,
                'solo_dias_completos'    => true,
                'requiere_documento'     => true,
                'anticipacion_min_dias'  => 30,
                'dentro_de_dias_evento'  => null,
                'activo'                 => true,
                'aud_usuario'            => 'system',
            ],
            [
                'codigo'                 => 'fallecimiento_familiar',
                'nombre'                 => 'Permiso por Fallecimiento de Familiar',
                'categoria'              => 'especial',
                'max_dias'               => null,
                'duracion_max_dias'      => 2,
                'permite_horas'          => false,
                'solo_dias_completos'    => true,
                'requiere_documento'     => false,
                'anticipacion_min_dias'  => null,
                'dentro_de_dias_evento'  => null,
                'activo'                 => true,
                'aud_usuario'            => 'system',
            ],
        ];

        foreach ($tipos as $tipo) {
            // Upsert por código para que sea re-ejecutable sin duplicar
            DB::connection('rrhh')
                ->table('tipos_permiso')
                ->updateOrInsert(
                    ['codigo' => $tipo['codigo']],
                    array_merge($tipo, [
                        'created_at' => now(),
                        'updated_at' => now(),
                    ])
                );
        }

        // Actualizar consulta_medica con los nuevos campos de reglas si ya existe
        DB::connection('rrhh')
            ->table('tipos_permiso')
            ->where('codigo', 'consulta_medica')
            ->update([
                'solo_dias_completos' => false, // permite horas
                'requiere_documento'  => true,
                'updated_at'          => now(),
            ]);

        // Actualizar dias_cadejo con reglas de solo días completos
        DB::connection('rrhh')
            ->table('tipos_permiso')
            ->where('codigo', 'dias_cadejo')
            ->update([
                'solo_dias_completos' => true,
                'requiere_documento'  => false,
                'anticipacion_min_dias' => 5,
                'updated_at'          => now(),
            ]);
    }

    public function down(): void
    {
        DB::connection('rrhh')
            ->table('tipos_permiso')
            ->whereIn('codigo', ['maternidad', 'paternidad', 'matrimonio', 'fallecimiento_familiar'])
            ->delete();
    }
};
