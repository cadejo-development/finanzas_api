<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('planillas_sp', function (Blueprint $table) {
            $table->id();
            $table->integer('anio');
            $table->integer('mes');
            $table->string('descripcion', 200)->nullable(); // ej. "Honorarios Noviembre 2026"
            $table->unsignedBigInteger('sucursal_id')->nullable();
            $table->string('estado', 20)->default('borrador'); // borrador|cerrada
            $table->decimal('total_honorarios', 12, 2)->default(0);
            $table->decimal('total_retencion', 12, 2)->default(0);
            $table->decimal('total_neto', 12, 2)->default(0);
            $table->unsignedBigInteger('creado_por')->nullable();
            $table->timestamps();
        });

        Schema::connection('rrhh')->create('planilla_sp_lineas', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('planilla_sp_id');
            $table->string('nombre', 150);             // nombre del prestador
            $table->string('nit', 20)->nullable();     // NIT o DUI
            $table->string('descripcion_servicio', 200)->nullable();
            $table->decimal('monto_honorario', 10, 2)->default(0);
            $table->decimal('retencion_isr', 10, 2)->default(0);  // 10% (Art. 156 Ley ISR)
            $table->decimal('liquido', 10, 2)->default(0);
            $table->text('notas')->nullable();
            $table->timestamps();

            $table->foreign('planilla_sp_id')->references('id')->on('planillas_sp')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('planilla_sp_lineas');
        Schema::connection('rrhh')->dropIfExists('planillas_sp');
    }
};
