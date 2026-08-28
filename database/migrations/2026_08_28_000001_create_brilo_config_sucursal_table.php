<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('brilo_config_sucursal', function (Blueprint $table) {
            $table->unsignedBigInteger('sucursal_id')->primary();
            $table->string('bodega_codigo', 20)->nullable();  // e.g. 'BOD-06'
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('brilo_config_sucursal');
    }
};
