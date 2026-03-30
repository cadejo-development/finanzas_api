<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->create('expediente_idiomas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('empleado_id')->index();
            $table->string('idioma', 80);
            $table->unsignedSmallInteger('nivel_habla')->default(0);
            $table->unsignedSmallInteger('nivel_escucha')->default(0);
            $table->unsignedSmallInteger('nivel_lectura')->default(0);
            $table->unsignedSmallInteger('nivel_escritura')->default(0);
            $table->text('notas')->nullable();
            $table->string('atestado_ruta', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('expediente_idiomas');
    }
};
