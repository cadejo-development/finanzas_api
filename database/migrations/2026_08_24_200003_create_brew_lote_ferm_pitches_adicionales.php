<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adiciones de levadura durante fermentación (repitches adicionales).
 * Cubre el caso: se usó repitch y no funcionó, hay que agregar más levadura
 * (seca, líquida o nuevo repitch). Separada del pitch inicial en brew_lote_fermentacion.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->create('brew_lote_ferm_pitches_adicionales', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('brew_lote_id');
            $table->date('fecha');
            $table->enum('tipo', ['seca', 'repitch', 'liquida'])->default('seca');
            $table->string('levadura_nombre', 120);
            $table->decimal('cantidad', 8, 2)->nullable();
            $table->string('unidad', 10)->default('g');
            $table->unsignedBigInteger('brew_levadura_lote_id')->nullable();
            $table->text('motivo')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('brew_lote_id')
                  ->references('id')->on('brew_lotes')->cascadeOnDelete();
            $table->foreign('brew_levadura_lote_id')
                  ->references('id')->on('brew_levadura_lotes')->nullOnDelete();

            $table->index('brew_lote_id');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('brew_lote_ferm_pitches_adicionales');
    }
};
