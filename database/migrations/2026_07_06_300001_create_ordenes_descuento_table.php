<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('ordenes_descuento', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->foreignId('acreedor_id')->constrained('acreedores');
            $table->foreignId('estado_id')->constrained('estados_orden_descuento');
            $table->decimal('monto', 10, 2);
            $table->string('referencia', 100)->nullable(); // nº expediente / caso
            $table->date('fecha_inicio');
            $table->date('fecha_fin')->nullable();         // null = indefinida
            $table->text('notas')->nullable();
            $table->string('aud_usuario', 100)->default('sistema');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('ordenes_descuento');
    }
};
