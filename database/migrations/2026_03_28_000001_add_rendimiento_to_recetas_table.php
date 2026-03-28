<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            // Rendimiento: cantidad que produce la receta/sub-receta (ej: 500)
            $table->decimal('rendimiento', 10, 4)->nullable()->after('platos_semana');
            // Unidad del rendimiento (ej: 'g', 'oz', 'lt', 'u')
            $table->string('rendimiento_unidad', 20)->nullable()->after('rendimiento');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->dropColumn(['rendimiento', 'rendimiento_unidad']);
        });
    }
};
