<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Asuetos de ley El Salvador 2026 (Art. 190 Código de Trabajo).
 * Nacionales: aplican a todos.
 * Departamentales San Salvador (geo_departamento_id = 6): Fiestas Agostinas.
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        $geo_ss = DB::connection('pgsql')
            ->table('geo_departamentos')
            ->where('codigo', '06')
            ->value('id');

        $nacionales = [
            ['fecha' => '2026-01-01', 'nombre' => 'Año Nuevo'],
            ['fecha' => '2026-04-02', 'nombre' => 'Jueves Santo'],
            ['fecha' => '2026-04-03', 'nombre' => 'Viernes Santo'],
            ['fecha' => '2026-04-04', 'nombre' => 'Sábado de Gloria'],
            ['fecha' => '2026-05-01', 'nombre' => 'Día del Trabajo'],
            ['fecha' => '2026-05-10', 'nombre' => 'Día de las Madres'],
            ['fecha' => '2026-06-21', 'nombre' => 'Día del Padre'],
            ['fecha' => '2026-09-15', 'nombre' => 'Día de la Independencia'],
            ['fecha' => '2026-11-02', 'nombre' => 'Día de los Difuntos'],
            ['fecha' => '2026-12-25', 'nombre' => 'Navidad'],
        ];

        foreach ($nacionales as $a) {
            DB::connection('pgsql')->table('asuetos')->updateOrInsert(
                ['fecha' => $a['fecha'], 'tipo' => 'nacional'],
                [
                    'nombre'              => $a['nombre'],
                    'tipo'                => 'nacional',
                    'geo_departamento_id' => null,
                    'activo'              => true,
                    'creado_por'          => 'sistema',
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]
            );
        }

        // Fiestas Agostinas — San Salvador
        $agostinas = [
            ['fecha' => '2026-08-03', 'nombre' => 'Fiestas Agostinas'],
            ['fecha' => '2026-08-04', 'nombre' => 'Fiestas Agostinas'],
            ['fecha' => '2026-08-06', 'nombre' => 'Día del Divino Salvador del Mundo'],
        ];

        foreach ($agostinas as $a) {
            DB::connection('pgsql')->table('asuetos')->updateOrInsert(
                ['fecha' => $a['fecha'], 'tipo' => 'departamental', 'geo_departamento_id' => $geo_ss],
                [
                    'nombre'              => $a['nombre'],
                    'tipo'                => 'departamental',
                    'geo_departamento_id' => $geo_ss,
                    'activo'              => true,
                    'creado_por'          => 'sistema',
                    'created_at'          => now(),
                    'updated_at'          => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::connection('pgsql')->table('asuetos')
            ->where('creado_por', 'sistema')
            ->whereYear('fecha', 2026)
            ->delete();
    }
};
