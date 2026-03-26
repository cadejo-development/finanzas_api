<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->create('unidades_medida', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->unique();   // clave corta: 'lb', 'oz', 'u'
            $table->string('nombre', 80);             // nombre completo: 'Libra', 'Onza'
            $table->string('descripcion', 150)->nullable();
            $table->integer('orden')->default(99);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        DB::connection('compras')->table('unidades_medida')->insert([
            ['codigo' => 'u',       'nombre' => 'Unidad',           'descripcion' => 'Unidad genérica (piezas, porciones individuales)', 'orden' => 1,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'lb',      'nombre' => 'Libra',            'descripcion' => 'Libra (454 g)', 'orden' => 2,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'oz',      'nombre' => 'Onza',             'descripcion' => 'Onza (28.35 g)', 'orden' => 3,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'oz fl',   'nombre' => 'Onza Fluida',      'descripcion' => 'Onza fluida (29.57 ml)', 'orden' => 4,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'lt',      'nombre' => 'Litro',            'descripcion' => 'Litro', 'orden' => 5,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'kg',      'nombre' => 'Kilogramo',        'descripcion' => 'Kilogramo (1000 g)', 'orden' => 6,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'galon',   'nombre' => 'Galón',            'descripcion' => 'Galón (3.785 lt)', 'orden' => 7,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'barril',  'nombre' => 'Barril',           'descripcion' => 'Barril (50 lt aprox.)', 'orden' => 8,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'tanda',   'nombre' => 'Tanda',            'descripcion' => 'Tanda / lote de producción', 'orden' => 9,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'porcion', 'nombre' => 'Porción',          'descripcion' => 'Porción estándar de servicio', 'orden' => 10, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'botella', 'nombre' => 'Botella',          'descripcion' => 'Botella (0.70–0.75 lt)', 'orden' => 11, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'caja',    'nombre' => 'Caja',             'descripcion' => 'Caja / empaque cerrado', 'orden' => 12, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'paquete', 'nombre' => 'Paquete',          'descripcion' => 'Paquete / sobre', 'orden' => 13, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('unidades_medida');
    }
};
