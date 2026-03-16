<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        if (!Schema::connection('rrhh')->hasTable('desvinculaciones')) {
            Schema::connection('rrhh')->create('desvinculaciones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empleado_id');
                $table->unsignedBigInteger('procesado_por_id')->comment('jefe_id que registra');
                $table->unsignedBigInteger('motivo_id');

                // tipo: despido | renuncia
                $table->string('tipo', 10);
                $table->date('fecha_efectiva')->comment('Fecha en que se hace efectiva la desvinculación');
                $table->text('observaciones')->nullable();

                // Denormalizado para registro histórico
                $table->string('empleado_nombre', 200)->nullable();
                $table->string('cargo_nombre', 150)->nullable();
                $table->string('sucursal_nombre', 150)->nullable();

                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('desvinculaciones');
    }
};
