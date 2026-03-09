<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 1) Agrega columna "tipo" a sucursales (operativa | area_corporativa)
 * 2) Limpia la tabla y la reinserta con el catálogo completo correcto.
 *    Safe: users.sucursal_id tiene nullOnDelete y actualmente todos son NULL.
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    private array $sucursales = [
        // Operativas (restaurantes / puntos de venta)
        ['codigo' => 'SUC-ZR',  'nombre' => 'RESTAURANTE ZONA ROSA',          'tipo' => 'operativa'],
        ['codigo' => 'SUC-SR',  'nombre' => 'RESTAURANTE SANTA ROSA',         'tipo' => 'operativa'],
        ['codigo' => 'SUC-LL',  'nombre' => 'RESTAURANTE LA LIBERTAD',        'tipo' => 'operativa'],
        ['codigo' => 'SUC-AE1', 'nombre' => 'RESTAURANTE AEROPUERTO 1',       'tipo' => 'operativa'],
        ['codigo' => 'SUC-AE2', 'nombre' => 'RESTAURANTE AEROPUERTO 2',       'tipo' => 'operativa'],
        ['codigo' => 'SUC-SM',  'nombre' => 'RESTAURANTE SAN MIGUEL',         'tipo' => 'operativa'],
        ['codigo' => 'SUC-PV',  'nombre' => 'RESTAURANTE PASEO VENECIA',      'tipo' => 'operativa'],
        ['codigo' => 'SUC-SE',  'nombre' => 'RESTAURANTE SANTA ELENA',        'tipo' => 'operativa'],
        ['codigo' => 'SUC-HZ',  'nombre' => 'RESTAURANTE HUIZUCAR',           'tipo' => 'operativa'],
        ['codigo' => 'SUC-OP',  'nombre' => 'RESTAURANTE OPICO',              'tipo' => 'operativa'],
        ['codigo' => 'SUC-GU',  'nombre' => 'RESTAURANTE CASA GUIROLA',       'tipo' => 'operativa'],
        ['codigo' => 'SUC-CO',  'nombre' => 'RESTAURANTE COATEPEQUE',         'tipo' => 'operativa'],
        ['codigo' => 'SUC-PD',  'nombre' => 'RESTAURANTE PUERTA DEL DIABLO',  'tipo' => 'operativa'],
        // Áreas corporativas (Casa Matriz)
        ['codigo' => 'SUC-CM',  'nombre' => 'CASA MATRIZ / CORPORATIVO',      'tipo' => 'area_corporativa'],
    ];

    public function up(): void
    {
        // 1) Agregar columna tipo si no existe
        if (!Schema::connection('pgsql')->hasColumn('sucursales', 'tipo')) {
            Schema::connection('pgsql')->table('sucursales', function (Blueprint $table) {
                $table->string('tipo', 30)->default('operativa')->after('codigo');
            });
        }

        // 2) Limpiar y reinsertar (safe: FK es nullOnDelete y todos los users tienen sucursal_id = NULL)
        DB::connection('pgsql')->table('sucursales')->delete();
        DB::connection('pgsql')->statement('ALTER SEQUENCE sucursales_id_seq RESTART WITH 1');

        $now = now();
        $rows = array_map(fn($s) => array_merge($s, [
            'aud_usuario' => 'migration',
            'created_at'  => $now,
            'updated_at'  => $now,
        ]), $this->sucursales);

        DB::connection('pgsql')->table('sucursales')->insert($rows);
    }

    public function down(): void
    {
        // Revertir solo tipo (no se puede re-crear la data original fácilmente)
        Schema::connection('pgsql')->table('sucursales', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
