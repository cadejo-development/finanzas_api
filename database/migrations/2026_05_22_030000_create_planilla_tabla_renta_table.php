<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('planilla_tabla_renta', function (Blueprint $table) {
            $table->id();
            $table->decimal('desde', 10, 2);
            $table->decimal('hasta', 10, 2)->nullable();
            $table->decimal('cuota_fija', 10, 2)->default(0);
            $table->decimal('porcentaje', 5, 2)->default(0);
            $table->decimal('sobre_exceso_de', 10, 2)->default(0);
            $table->date('vigente_desde');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('planilla_tabla_renta');
    }
};
