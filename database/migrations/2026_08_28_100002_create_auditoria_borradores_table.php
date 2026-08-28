<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->create('auditoria_borradores', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('auditoria_id');
            $table->string('aud_usuario');          // email del auditor
            $table->jsonb('payload')->default('{}'); // {producto_id: {cantidad_auditor, observacion}}
            $table->timestamps();

            $table->unique(['auditoria_id', 'aud_usuario']);
            $table->foreign('auditoria_id')
                  ->references('id')->on('conteo_auditorias')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('auditoria_borradores');
    }
};
