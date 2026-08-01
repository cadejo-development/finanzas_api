<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('prod_seg_historico', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('sucursal_id');
            $table->unsignedBigInteger('producto_id');
            $table->date('fecha');
            $table->timestamp('sync_at');                      // cuándo se ejecutó el sync
            $table->decimal('brilo_stock', 18, 6)->nullable();
            $table->decimal('conteo_fisico', 18, 6)->nullable();
            $table->decimal('diferencia', 18, 6)->nullable();  // brilo_stock - conteo_fisico
            $table->timestamps();

            $table->unique(['sucursal_id', 'producto_id', 'fecha'], 'prod_seg_hist_unique');
            $table->index(['sucursal_id', 'fecha'], 'prod_seg_hist_suc_fecha');
            $table->index(['producto_id', 'fecha'],  'prod_seg_hist_prod_fecha');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('prod_seg_historico');
    }
};
