<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->create('conteo_borradores', function (Blueprint $table) {
            $table->id();
            $table->integer('sucursal_id');
            $table->string('aud_usuario', 120);
            $table->date('fecha_conteo');
            $table->jsonb('payload');         // { contado, subtotales, extras, secciones }
            $table->timestamps();

            $table->unique(['sucursal_id', 'aud_usuario']);
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('conteo_borradores');
    }
};
