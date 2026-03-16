<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        if (!Schema::connection('rrhh')->hasTable('incapacidades')) {
            Schema::connection('rrhh')->create('incapacidades', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empleado_id');
                $table->unsignedBigInteger('tipo_incapacidad_id');
                $table->unsignedBigInteger('registrado_por_id')->comment('jefe_id que registra');

                $table->date('fecha_inicio');
                $table->date('fecha_fin');
                $table->integer('dias')->comment('Días de incapacidad');

                // Adjunto
                $table->string('archivo_nombre')->nullable();
                $table->string('archivo_ruta')->nullable();

                // Homologación (aplica a tipo HOMOLOGADA)
                $table->boolean('homologada')->default(false);
                $table->unsignedBigInteger('homologada_por_id')->nullable();
                $table->timestamp('homologada_en')->nullable();

                $table->text('observaciones')->nullable();
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('incapacidades');
    }
};
