<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        if (!Schema::connection('rrhh')->hasTable('traslados')) {
            Schema::connection('rrhh')->create('traslados', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empleado_id');
                $table->unsignedBigInteger('solicitado_por_id')->comment('jefe_id que solicita');

                // Origen (denormalizado por histórico)
                $table->unsignedBigInteger('sucursal_origen_id')->nullable();
                $table->string('sucursal_origen_nombre', 150)->nullable();
                $table->unsignedBigInteger('cargo_origen_id')->nullable();
                $table->string('cargo_origen_nombre', 150)->nullable();

                // Destino
                $table->unsignedBigInteger('sucursal_destino_id');
                $table->string('sucursal_destino_nombre', 150)->nullable();
                $table->unsignedBigInteger('cargo_destino_id')->nullable();
                $table->string('cargo_destino_nombre', 150)->nullable();

                $table->date('fecha_efectiva');
                $table->text('motivo')->nullable();

                // estado: pendiente | aprobado | rechazado
                $table->string('estado', 20)->default('pendiente');

                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('traslados');
    }
};
