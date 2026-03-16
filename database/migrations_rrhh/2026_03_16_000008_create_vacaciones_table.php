<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        if (!Schema::connection('rrhh')->hasTable('vacaciones')) {
            Schema::connection('rrhh')->create('vacaciones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empleado_id');
                $table->unsignedBigInteger('jefe_id');

                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                $table->decimal('dias', 4, 1)->comment('Días hábiles de vacación');

                // estado: pendiente | aprobado | rechazado
                $table->string('estado', 20)->default('pendiente');
                $table->text('observaciones')->nullable();

                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('vacaciones');
    }
};
