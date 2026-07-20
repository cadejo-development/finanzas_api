<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('brew_lote_llenado_botellas_corridas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->integer('numero_corrida');
            $table->date('fecha')->nullable();
            $table->decimal('bbt_vol_inicio', 8, 2)->nullable()->comment('Vol BBT al inicio de esta llenada (L)');
            $table->decimal('bbt_vol_fin', 8, 2)->nullable()->comment('Vol BBT al final de esta llenada (L)');
            $table->integer('botellas_buenas')->nullable()->default(0);
            $table->integer('bajo_nivel')->nullable()->default(0)->comment('Botellas rechazadas por bajo nivel');
            $table->integer('derrame')->nullable()->default(0)->comment('Botellas rechazadas por derrame');
            $table->decimal('vol_llenado_l', 8, 2)->nullable()->comment('Vol real llenado en esta corrida (L)');
            $table->decimal('eficiencia', 5, 2)->nullable()->comment('% eficiencia de esta llenada');
            $table->integer('cajas')->nullable()->default(0);
            $table->decimal('fg_real', 6, 4)->nullable();
            $table->decimal('co2_vol', 5, 2)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });

        Schema::connection('compras')->create('brew_lote_llenado_barriles_corridas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brew_lote_id')->constrained('brew_lotes')->cascadeOnDelete();
            $table->integer('numero_corrida');
            $table->date('fecha')->nullable();
            $table->decimal('bbt_vol_inicio', 8, 2)->nullable()->comment('Vol BBT al inicio (L)');
            $table->decimal('bbt_vol_fin', 8, 2)->nullable()->comment('Vol BBT al final (L)');
            $table->integer('barriles_6th')->default(0)->comment('Barriles 1/6 (~19.8 L)');
            $table->integer('barriles_half')->default(0)->comment('Barriles 1/2 (~58.7 L)');
            $table->decimal('derrame', 6, 2)->nullable()->default(0)->comment('Litros de derrame');
            $table->decimal('eficiencia', 5, 2)->nullable()->comment('% eficiencia de esta llenada');
            $table->decimal('fg_real', 6, 4)->nullable();
            $table->decimal('co2_psi', 6, 2)->nullable();
            $table->text('notas')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('brew_lote_llenado_barriles_corridas');
        Schema::connection('compras')->dropIfExists('brew_lote_llenado_botellas_corridas');
    }
};
