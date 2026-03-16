<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        if (!Schema::connection('rrhh')->hasTable('amonestaciones')) {
            Schema::connection('rrhh')->create('amonestaciones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empleado_id');
                $table->unsignedBigInteger('jefe_id');
                $table->unsignedBigInteger('tipo_falta_id');

                $table->date('fecha_amonestacion');
                $table->text('descripcion');
                $table->text('accion_tomada')->nullable();

                // Suspensión
                $table->boolean('aplica_suspension')->default(false);
                // Los días específicos van en la tabla dias_suspension

                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('amonestaciones');
    }
};
