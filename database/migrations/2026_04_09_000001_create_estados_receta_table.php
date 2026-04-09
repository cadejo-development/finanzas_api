<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Crea la tabla catálogo estados_receta y agrega estado_id a recetas.
 * Todas las recetas existentes (migradas desde SQL Server) se marcan como 'activa'.
 * Las recetas nuevas creadas desde el formulario arrancan en 'borrador' (manejado en código).
 */
return new class extends Migration
{
    public function getConnection(): string { return 'compras'; }

    public function up(): void
    {
        // 1 ── Catálogo de estados
        Schema::connection('compras')->create('estados_receta', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 100);
            $table->string('color', 20)->default('gray')->comment('Hint de color para el frontend: gray, yellow, blue, green, red');
            $table->integer('orden')->default(0)->comment('Orden de visualización');
            $table->timestamps();
        });

        // 2 ── Seed de los 5 estados acordados
        DB::connection('compras')->table('estados_receta')->insert([
            ['codigo' => 'borrador',    'nombre' => 'Borrador',    'color' => 'gray',   'orden' => 1, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'finalizada',  'nombre' => 'Finalizada',  'color' => 'yellow', 'orden' => 2, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'autorizada',  'nombre' => 'Autorizada',  'color' => 'blue',   'orden' => 3, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'activa',      'nombre' => 'Activa',      'color' => 'green',  'orden' => 4, 'created_at' => now(), 'updated_at' => now()],
            ['codigo' => 'inactiva',    'nombre' => 'Inactiva',    'color' => 'red',    'orden' => 5, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // 3 ── Agregar estado_id a recetas (nullable mientras se migra)
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->unsignedBigInteger('estado_id')->nullable()->after('activa');
        });

        // 4 ── Todas las recetas existentes → 'activa' (vienen de SQL Server, ya están en Brilo)
        $activaId = DB::connection('compras')->table('estados_receta')->where('codigo', 'activa')->value('id');
        DB::connection('compras')->table('recetas')->update(['estado_id' => $activaId]);

        // 5 ── Hacer la columna NOT NULL ahora que todas tienen valor
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->unsignedBigInteger('estado_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->dropColumn('estado_id');
        });
        Schema::connection('compras')->dropIfExists('estados_receta');
    }
};
