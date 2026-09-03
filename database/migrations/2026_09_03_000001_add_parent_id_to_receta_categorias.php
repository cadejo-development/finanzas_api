<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->table('receta_categorias', function (Blueprint $table) {
            $table->unsignedBigInteger('parent_id')->nullable()->after('orden');
        });

        $now = now();

        $aId = DB::connection('compras')->table('receta_categorias')->insertGetId([
            'nombre'     => 'Alimentos',
            'key'        => 'alimentos',
            'orden'      => 0,
            'parent_id'  => null,
            'activa'     => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $bId = DB::connection('compras')->table('receta_categorias')->insertGetId([
            'nombre'     => 'Bebidas',
            'key'        => 'bebidas',
            'orden'      => 1,
            'parent_id'  => null,
            'activa'     => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        // Categorías cuyo nombre contiene "Bebida" → Bebidas
        DB::connection('compras')->table('receta_categorias')
            ->whereNull('parent_id')
            ->whereNotIn('id', [$aId, $bId])
            ->where('nombre', 'ilike', '%Bebida%')
            ->update(['parent_id' => $bId, 'updated_at' => $now]);

        // El resto (Platos, etc.) → Alimentos
        DB::connection('compras')->table('receta_categorias')
            ->whereNull('parent_id')
            ->whereNotIn('id', [$aId, $bId])
            ->update(['parent_id' => $aId, 'updated_at' => $now]);
    }

    public function down(): void
    {
        DB::connection('compras')->table('receta_categorias')
            ->whereIn('key', ['alimentos', 'bebidas'])
            ->whereNull('parent_id')
            ->delete();

        Schema::connection('compras')->table('receta_categorias', function (Blueprint $table) {
            $table->dropColumn('parent_id');
        });
    }
};
