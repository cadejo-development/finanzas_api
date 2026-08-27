<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('merma_audit_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inventario_id')->nullable();
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->string('usuario_nombre', 150)->nullable();
            $table->unsignedInteger('sucursal_id')->nullable();
            $table->string('sucursal_nombre', 100)->nullable();
            $table->string('evento', 200);
            $table->text('valor_original')->nullable();
            $table->text('valor_nuevo')->nullable();
            $table->text('comentario')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index('inventario_id');
            $table->index('sucursal_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_audit_log');
    }
};
