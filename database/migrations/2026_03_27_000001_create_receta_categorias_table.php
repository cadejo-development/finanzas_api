<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Crear tabla catálogo de categorías
        Schema::connection('compras')->create('receta_categorias', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 100)->unique();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        // 2. Poblar catálogo con los tipos únicos que ya existen en recetas
        $tipos = DB::connection('compras')
            ->table('recetas')
            ->whereNotNull('tipo')
            ->where('tipo', '!=', '')
            ->distinct()
            ->orderBy('tipo')
            ->pluck('tipo');

        $now = now();
        foreach ($tipos as $tipo) {
            DB::connection('compras')->table('receta_categorias')->insertOrIgnore([
                'nombre'     => $tipo,
                'activa'     => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // 3. Agregar columna categoria_id a recetas
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->unsignedBigInteger('categoria_id')->nullable()->after('tipo');
        });

        // 4. Migrar datos: enlazar cada receta a su categoria por nombre
        $categorias = DB::connection('compras')->table('receta_categorias')->get();
        foreach ($categorias as $cat) {
            DB::connection('compras')
                ->table('recetas')
                ->where('tipo', $cat->nombre)
                ->update(['categoria_id' => $cat->id]);
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->dropColumn('categoria_id');
        });
        Schema::connection('compras')->dropIfExists('receta_categorias');
    }
};
