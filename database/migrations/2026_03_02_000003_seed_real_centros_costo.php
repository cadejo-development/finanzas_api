<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Poblar la tabla centros_costo con los 44 registros reales del sistema core.
 * Fuente: public.centroscosto (cecoid, cecocodigo, ceconombre, cecoactivo, cecoidpadre, cecoessub)
 *
 * cecoessub = 1  → centro operativo (hoja/sub-centro)
 * cecoessub = 0  → agrupador / nodo padre
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        // Vaciar tabla existente (datos falsos del seeder previo) y reiniciar secuencia
        DB::connection('pgsql')->statement('TRUNCATE TABLE centros_costo RESTART IDENTITY CASCADE');

        // Forzamos el id original (cecoid) para mantener las referencias padre_id intactas
        // Usamos setval después para dejar la secuencia por encima de 84
        DB::connection('pgsql')->statement('ALTER TABLE centros_costo DISABLE TRIGGER ALL');

        $registros = [
            // id   codigo          nombre                                    padre_id  activo   es_sub
            ['id' => 1,  'codigo' => 'CECO-02-01', 'nombre' => 'RESTAURANTE ZONA ROSA',          'padre_id' => 9,    'activo' => true, 'es_sub' => true],
            ['id' => 2,  'codigo' => 'CECO-01-01', 'nombre' => 'VENTAS',                          'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 3,  'codigo' => 'CECO-01-03', 'nombre' => 'MERCADEO',                        'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 4,  'codigo' => 'CECO-01-04', 'nombre' => 'ADMINISTRACION',                  'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 7,  'codigo' => 'CECO-01-02', 'nombre' => 'PRODUCCION',                      'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 9,  'codigo' => 'CECO-02',    'nombre' => 'RESTAURANTE ZONA ROSA',           'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 10, 'codigo' => 'CECO-01-05', 'nombre' => 'BODEGA',                          'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 11, 'codigo' => 'CECO-03',    'nombre' => 'RESTAURANTE SANTA ROSA',          'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 12, 'codigo' => 'CECO-01-06', 'nombre' => 'GERENCIA GENERAL',                'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 13, 'codigo' => 'CECO-05',    'nombre' => 'RESTAURANTE LA LIBERTAD',         'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 16, 'codigo' => 'CECO-01-08', 'nombre' => 'RECURSOS HUMANOS',                'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 18, 'codigo' => 'CECO-03-01', 'nombre' => 'RESTAURANTE SANTA ROSA',          'padre_id' => 11,   'activo' => true, 'es_sub' => true],
            ['id' => 20, 'codigo' => 'CECO-05-01', 'nombre' => 'RESTAURANTE LA LIBERTAD',         'padre_id' => 13,   'activo' => true, 'es_sub' => true],
            ['id' => 21, 'codigo' => 'CECO-01',    'nombre' => 'CASA MATRIZ',                     'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 22, 'codigo' => 'CECO-06',    'nombre' => 'RESTAURANTE AEROPUERTO-1',        'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 24, 'codigo' => 'CECO-06-01', 'nombre' => 'REST- AEROPUERTO-1',              'padre_id' => 22,   'activo' => true, 'es_sub' => true],
            ['id' => 25, 'codigo' => 'CECO-07-01', 'nombre' => 'RESTA- AEROPUERTO-2',             'padre_id' => 26,   'activo' => true, 'es_sub' => true],
            ['id' => 26, 'codigo' => 'CECO-07',    'nombre' => 'RESTAURANTE AEROPUERTO-2',        'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 28, 'codigo' => 'CECO-01-09', 'nombre' => 'EVENTOS EXTERNOS',                'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 54, 'codigo' => 'CECO-08',    'nombre' => 'RESTAURANTE SAN MIGUEL',          'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 55, 'codigo' => 'CECO-08-01', 'nombre' => 'RESTAURANTE SAN MIGUEL',          'padre_id' => 54,   'activo' => true, 'es_sub' => true],
            ['id' => 56, 'codigo' => 'CECO-09',    'nombre' => 'RESTAURANTE PASEO VENECIA',       'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 57, 'codigo' => 'CECO-09-01', 'nombre' => 'RESTAURANTE PASEO VENECIA',       'padre_id' => 56,   'activo' => true, 'es_sub' => true],
            ['id' => 59, 'codigo' => 'CECO-10',    'nombre' => 'RESTAURANTE SANTA ELENA',         'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 60, 'codigo' => 'CECO-10-01', 'nombre' => 'RESTAURANTE SANTA ELENA',         'padre_id' => 59,   'activo' => true, 'es_sub' => true],
            ['id' => 64, 'codigo' => 'CECO-12',    'nombre' => 'RESTAURANTE HUIZUCAR',            'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 65, 'codigo' => 'CECO-12-01', 'nombre' => 'RESTAURANTE HUIZUCAR',            'padre_id' => 64,   'activo' => true, 'es_sub' => true],
            ['id' => 66, 'codigo' => 'CECO-13',    'nombre' => 'RESTAURANTE OPICO',               'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 67, 'codigo' => 'CECO-13-01', 'nombre' => 'RESTAURANTE OPICO',               'padre_id' => 66,   'activo' => true, 'es_sub' => true],
            ['id' => 69, 'codigo' => 'CECO-01-10', 'nombre' => 'CENTRO DE PRODUCCION',            'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 70, 'codigo' => 'CECO-12-02', 'nombre' => 'EVENTOS HUIZUCAR',                'padre_id' => 64,   'activo' => true, 'es_sub' => true],
            ['id' => 71, 'codigo' => 'CECO-01-11', 'nombre' => 'OPERACIONES RESTAURANTES',        'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 72, 'codigo' => 'CECO-01-12', 'nombre' => 'LOGISTICA',                       'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 73, 'codigo' => 'CECO-01-13', 'nombre' => 'MANTENIMIENTO',                   'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 74, 'codigo' => 'CECO-01-14', 'nombre' => 'EVENTOS INTERNOS',                'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 75, 'codigo' => 'CECO-07-02', 'nombre' => 'RESTA MALCRIADAS AE2',            'padre_id' => 26,   'activo' => true, 'es_sub' => true],
            ['id' => 76, 'codigo' => 'CECO-14',    'nombre' => 'RESTAURANTE CASA GUIROLA',        'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 77, 'codigo' => 'CECO-15',    'nombre' => 'RESTAURANTE COATEPEQUE',          'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 78, 'codigo' => 'CECO-16',    'nombre' => 'RESTAURANTE PUERTA DEL DIABLO',   'padre_id' => null, 'activo' => true, 'es_sub' => false],
            ['id' => 79, 'codigo' => 'CECO-14-01', 'nombre' => 'RESTAURANTE CASA GUIROLA',        'padre_id' => 76,   'activo' => true, 'es_sub' => true],
            ['id' => 80, 'codigo' => 'CECO-15-01', 'nombre' => 'RESTAURANTE COATEPEQUE',          'padre_id' => 77,   'activo' => true, 'es_sub' => true],
            ['id' => 81, 'codigo' => 'CECO-16-01', 'nombre' => 'RESTAURANTE PUERTA DEL DIABLO',   'padre_id' => 78,   'activo' => true, 'es_sub' => true],
            ['id' => 82, 'codigo' => 'CECO-14-02', 'nombre' => 'EVENTOS CASA GUIROLA',            'padre_id' => 76,   'activo' => true, 'es_sub' => true],
            ['id' => 83, 'codigo' => 'CECO-01-15', 'nombre' => 'PLANTA SBC',                      'padre_id' => 21,   'activo' => true, 'es_sub' => true],
            ['id' => 84, 'codigo' => 'CECO-05-02', 'nombre' => 'ALOJAMIENTO LA LIBERTAD',         'padre_id' => 13,   'activo' => true, 'es_sub' => true],
        ];

        DB::connection('pgsql')->table('centros_costo')->insert(
            collect($registros)->map(fn($r) => [
                'id'         => $r['id'],
                'codigo'     => $r['codigo'],
                'nombre'     => $r['nombre'],
                'padre_id'   => $r['padre_id'],
                'es_sub'     => $r['es_sub'],
                'activo'     => $r['activo'],
                'aud_usuario' => 'seed',
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray()
        );

        DB::connection('pgsql')->statement('ALTER TABLE centros_costo ENABLE TRIGGER ALL');

        // Dejar la secuencia por encima del id máximo (84) para futuros inserts
        DB::connection('pgsql')->statement("SELECT setval(pg_get_serial_sequence('centros_costo','id'), 100, false)");
    }

    public function down(): void
    {
        DB::connection('pgsql')->statement('TRUNCATE TABLE centros_costo RESTART IDENTITY CASCADE');
    }
};
