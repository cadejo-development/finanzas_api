<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('tipos_contrato', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre', 120);
            $table->text('descripcion')->nullable();
            $table->unsignedSmallInteger('duracion_dias')->nullable(); // null = indefinido
            $table->boolean('es_periodo_prueba')->default(false);      // true = servicios profesionales
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();
        });

        // Sembrar tipos iniciales
        DB::connection('rrhh')->table('tipos_contrato')->insert([
            [
                'codigo'           => 'SERV-PROF',
                'nombre'           => 'Servicios Profesionales',
                'descripcion'      => 'Contrato de servicios profesionales para período de prueba de 30 días.',
                'duracion_dias'    => 30,
                'es_periodo_prueba'=> true,
                'activo'           => true,
                'aud_usuario'      => 'system',
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
            [
                'codigo'           => 'INDEFINIDO',
                'nombre'           => 'Contrato Indefinido',
                'descripcion'      => 'Contrato por tiempo indefinido. Se genera al aprobar el período de prueba.',
                'duracion_dias'    => null,
                'es_periodo_prueba'=> false,
                'activo'           => true,
                'aud_usuario'      => 'system',
                'created_at'       => now(),
                'updated_at'       => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('tipos_contrato');
    }
};
