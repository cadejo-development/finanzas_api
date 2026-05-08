<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // Lote principal
        Schema::connection('compras')->create('brew_lotes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_receta_id')->constrained('brew_recetas');
            $table->string('codigo_lote', 30)->unique();
            $table->date('fecha_coccion');
            $table->string('estado', 30)->default('coccion')
                ->comment('coccion|filtracion|fermentacion|llenado|completo');
            $table->string('cervecero')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Cocción
        Schema::connection('compras')->create('brew_lote_coccion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->decimal('og_real', 6, 4)->nullable();
            $table->decimal('vol_preboil_real', 8, 2)->nullable();
            $table->decimal('vol_postboil_real', 8, 2)->nullable();
            $table->decimal('temp_mash_real', 5, 1)->nullable();
            $table->integer('tiempo_boil_min')->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Pasos de macerado registrados en el lote
        Schema::connection('compras')->create('brew_lote_macerado_pasos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->integer('orden')->default(0);
            $table->string('nombre')->nullable();
            $table->decimal('temp_objetivo', 5, 1)->nullable();
            $table->decimal('temp_real', 5, 1)->nullable();
            $table->integer('tiempo_min')->nullable();
            $table->string('hora_inicio', 8)->nullable();
            $table->string('hora_fin', 8)->nullable();
            $table->timestamps();
        });

        // Pasos de boil/whirlpool registrados en el lote
        Schema::connection('compras')->create('brew_lote_boil_pasos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->integer('orden')->default(0);
            $table->string('descripcion');
            $table->integer('tiempo_min')->nullable();
            $table->string('hora', 8)->nullable();
            $table->boolean('completado')->default(false);
            $table->timestamps();
        });

        // Filtración
        Schema::connection('compras')->create('brew_lote_filtracion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->decimal('vol_bbt_real', 8, 2)->nullable()->comment('Litros transferidos al BBT');
            $table->decimal('og_bbt', 6, 4)->nullable();
            $table->decimal('temp_transfer', 5, 1)->nullable();
            $table->integer('num_corridas')->nullable()->default(1);
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Corridas de filtración
        Schema::connection('compras')->create('brew_lote_filtracion_corridas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->integer('numero_corrida');
            $table->decimal('vol_litros', 8, 2)->nullable();
            $table->decimal('densidad', 6, 4)->nullable();
            $table->string('hora', 8)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Fermentación pitch
        Schema::connection('compras')->create('brew_lote_fermentacion', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->date('fecha_pitch');
            $table->decimal('temp_pitch', 5, 1)->nullable();
            $table->decimal('og_pitch', 6, 4)->nullable();
            $table->decimal('vol_pitch', 8, 2)->nullable();
            $table->string('levadura_nombre')->nullable();
            $table->decimal('levadura_cantidad_g', 8, 2)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Seguimiento diario de fermentación
        Schema::connection('compras')->create('brew_lote_ferm_seguimiento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->integer('dia');
            $table->date('fecha');
            $table->decimal('gravedad', 6, 4)->nullable();
            $table->decimal('temp', 5, 1)->nullable();
            $table->decimal('ph', 4, 2)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Llenado de botellas
        Schema::connection('compras')->create('brew_lote_llenado_botellas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->date('fecha');
            $table->decimal('vol_inicio', 8, 2)->nullable()->comment('Litros al inicio del llenado');
            $table->decimal('vol_fin', 8, 2)->nullable()->comment('Litros al final (merma)');
            $table->integer('botellas_buenas')->nullable();
            $table->integer('botellas_rotas')->nullable();
            $table->decimal('fg_real', 6, 4)->nullable();
            $table->decimal('co2_vol', 5, 2)->nullable()->comment('Volúmenes CO2 carbonatación');
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        // Llenado de barriles
        Schema::connection('compras')->create('brew_lote_llenado_barriles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->date('fecha');
            $table->integer('barriles_6th')->default(0)->comment('Barriles 1/6 = 19.8L');
            $table->integer('barriles_half')->default(0)->comment('Barriles 1/2 = 58.7L');
            $table->decimal('vol_total_barriles', 8, 2)->nullable();
            $table->decimal('fg_real', 6, 4)->nullable();
            $table->decimal('co2_psi', 6, 2)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('brew_lote_llenado_barriles');
        Schema::connection('compras')->dropIfExists('brew_lote_llenado_botellas');
        Schema::connection('compras')->dropIfExists('brew_lote_ferm_seguimiento');
        Schema::connection('compras')->dropIfExists('brew_lote_fermentacion');
        Schema::connection('compras')->dropIfExists('brew_lote_filtracion_corridas');
        Schema::connection('compras')->dropIfExists('brew_lote_filtracion');
        Schema::connection('compras')->dropIfExists('brew_lote_boil_pasos');
        Schema::connection('compras')->dropIfExists('brew_lote_macerado_pasos');
        Schema::connection('compras')->dropIfExists('brew_lote_coccion');
        Schema::connection('compras')->dropIfExists('brew_lotes');
    }
};
