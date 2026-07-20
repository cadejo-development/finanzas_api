<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('brew_receta_dias_objetivo', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_receta_id')
                ->constrained('brew_recetas')
                ->cascadeOnDelete();
            $table->integer('dia')->comment('Número de día (1, 2, 3...)');
            $table->enum('etapa', ['fermentacion', 'maduracion'])->default('fermentacion');
            $table->decimal('plato_obj', 5, 1)->nullable()->comment('Grados Plato objetivo del día');
            $table->decimal('temp_obj', 5, 1)->nullable()->comment('Temperatura objetivo del día (°C)');
            $table->decimal('ph_obj', 4, 2)->nullable()->comment('pH objetivo del día');
            $table->text('notas_objetivo')->nullable()->comment('Acción sugerida o nota del día');
            $table->timestamps();

            $table->unique(['brew_receta_id', 'dia', 'etapa'], 'receta_dia_etapa_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('brew_receta_dias_objetivo');
    }
};
