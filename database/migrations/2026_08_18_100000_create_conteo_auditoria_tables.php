<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('conteo_auditorias', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('sucursal_id');
            $table->date('fecha_conteo');
            $table->string('estado', 20)->default('activa'); // activa | cerrada
            $table->unsignedBigInteger('auditado_por')->nullable();
            $table->string('auditado_por_nombre', 200)->nullable();
            $table->text('firma_auditoria')->nullable();
            $table->timestamp('cerrado_at')->nullable();
            $table->timestamps();
            $table->unique(['sucursal_id', 'fecha_conteo']);
            $table->index('sucursal_id');
        });

        Schema::connection('compras')->create('conteo_auditoria_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('auditoria_id');
            $table->unsignedBigInteger('producto_id');
            $table->string('producto_nombre', 300);
            $table->decimal('cantidad_contador', 15, 5);
            $table->string('unidad', 30);
            $table->decimal('cantidad_auditor', 15, 5)->nullable();
            $table->boolean('comprobado')->default(false);
            $table->string('seccion', 50)->nullable();
            $table->text('observacion')->nullable();
            $table->timestamps();
            $table->index('auditoria_id');
            $table->unique(['auditoria_id', 'producto_id']);
        });

        Schema::connection('compras')->create('conteo_auditoria_respuestas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('auditoria_id');
            $table->string('decision', 20); // aprobada | rechazada
            $table->text('comentarios')->nullable();
            $table->unsignedBigInteger('respondido_por')->nullable();
            $table->string('respondido_por_nombre', 200)->nullable();
            $table->timestamps();
            $table->index('auditoria_id');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('conteo_auditoria_respuestas');
        Schema::connection('compras')->dropIfExists('conteo_auditoria_items');
        Schema::connection('compras')->dropIfExists('conteo_auditorias');
    }
};
