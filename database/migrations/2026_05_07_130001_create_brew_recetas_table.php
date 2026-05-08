<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('brew_recetas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('estilo')->nullable();
            $table->string('codigo', 30)->nullable()->unique();
            $table->decimal('vol_preboil', 8, 2)->nullable()->comment('Litros pre-boil');
            $table->decimal('vol_postboil', 8, 2)->nullable()->comment('Litros post-boil / Kettle');
            $table->decimal('vol_bbt', 8, 2)->nullable()->comment('Litros en BBT');
            $table->decimal('og', 6, 4)->nullable()->comment('Original Gravity');
            $table->decimal('fg', 6, 4)->nullable()->comment('Final Gravity');
            $table->decimal('abv', 5, 2)->nullable();
            $table->decimal('ibu', 6, 2)->nullable();
            $table->decimal('srm', 6, 2)->nullable();
            $table->decimal('eficiencia_macerado', 5, 2)->nullable()->comment('%');
            $table->integer('dias_ferm')->default(14)->comment('Días de seguimiento fermentación');
            $table->text('notas')->nullable();
            $table->boolean('activa')->default(true);
            $table->timestamps();
        });

        Schema::connection('compras')->create('brew_receta_maltas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_receta_id')->constrained('brew_recetas')->cascadeOnDelete();
            $table->integer('orden')->default(0);
            $table->string('nombre');
            $table->decimal('cantidad_kg', 8, 3);
            $table->decimal('lovibond', 6, 2)->nullable();
            $table->string('proveedor')->nullable();
            $table->timestamps();
        });

        Schema::connection('compras')->create('brew_receta_lupulos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_receta_id')->constrained('brew_recetas')->cascadeOnDelete();
            $table->integer('orden')->default(0);
            $table->string('nombre');
            $table->decimal('cantidad_g', 8, 2)->comment('gramos');
            $table->decimal('alpha', 5, 2)->nullable()->comment('% alfa ácidos');
            $table->string('uso')->nullable()->comment('Boil, Whirlpool, Dry Hop');
            $table->integer('tiempo_min')->nullable();
            $table->timestamps();
        });

        Schema::connection('compras')->create('brew_receta_minerales', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_receta_id')->constrained('brew_recetas')->cascadeOnDelete();
            $table->integer('orden')->default(0);
            $table->string('nombre');
            $table->decimal('cantidad_g', 8, 3);
            $table->string('fase')->nullable()->comment('Macerado, Boil');
            $table->timestamps();
        });

        Schema::connection('compras')->create('brew_receta_levaduras', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_receta_id')->constrained('brew_recetas')->cascadeOnDelete();
            $table->string('nombre');
            $table->string('codigo')->nullable();
            $table->string('proveedor')->nullable();
            $table->decimal('cantidad_g', 8, 2)->nullable();
            $table->decimal('temp_min', 5, 1)->nullable();
            $table->decimal('temp_max', 5, 1)->nullable();
            $table->timestamps();
        });

        Schema::connection('compras')->create('brew_receta_macerado_pasos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_receta_id')->constrained('brew_recetas')->cascadeOnDelete();
            $table->integer('orden')->default(0);
            $table->string('nombre')->nullable()->comment('Proteica, Sacarificación, Mash-out, etc.');
            $table->decimal('temp_objetivo', 5, 1)->nullable();
            $table->integer('tiempo_min')->nullable();
            $table->timestamps();
        });

        Schema::connection('compras')->create('brew_receta_boil_pasos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_receta_id')->constrained('brew_recetas')->cascadeOnDelete();
            $table->integer('orden')->default(0);
            $table->string('descripcion');
            $table->integer('tiempo_min')->nullable()->comment('Minutos desde fin de boil');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('brew_receta_boil_pasos');
        Schema::connection('compras')->dropIfExists('brew_receta_macerado_pasos');
        Schema::connection('compras')->dropIfExists('brew_receta_levaduras');
        Schema::connection('compras')->dropIfExists('brew_receta_minerales');
        Schema::connection('compras')->dropIfExists('brew_receta_lupulos');
        Schema::connection('compras')->dropIfExists('brew_receta_maltas');
        Schema::connection('compras')->dropIfExists('brew_recetas');
    }
};
