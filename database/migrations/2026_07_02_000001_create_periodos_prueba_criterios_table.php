<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('periodos_prueba_criterios', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('orden')->default(0);
            $table->string('pregunta');
            $table->string('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // Seed — 10 criterios estándar
        DB::connection('rrhh')->table('periodos_prueba_criterios')->insert([
            ['orden' => 1,  'pregunta' => '¿El colaborador demuestra puntualidad y asistencia regular?',           'descripcion' => 'Llega a tiempo y no ha tenido ausencias injustificadas.'],
            ['orden' => 2,  'pregunta' => '¿Cumple con las tareas asignadas en tiempo y forma?',                   'descripcion' => 'Entrega resultados dentro de los plazos esperados.'],
            ['orden' => 3,  'pregunta' => '¿Muestra actitud positiva y disposición para aprender?',               'descripcion' => 'Receptividad ante correcciones y nuevas instrucciones.'],
            ['orden' => 4,  'pregunta' => '¿Se adapta correctamente a los procesos y normas de la empresa?',      'descripcion' => 'Respeta políticas internas, uniformes y protocolos.'],
            ['orden' => 5,  'pregunta' => '¿Trabaja bien en equipo con sus compañeros?',                          'descripcion' => 'Colabora, se comunica y apoya a sus colegas.'],
            ['orden' => 6,  'pregunta' => '¿Muestra iniciativa y proactividad en sus labores?',                   'descripcion' => 'Actúa sin necesitar supervisión constante.'],
            ['orden' => 7,  'pregunta' => '¿Atiende al cliente con calidad y cordialidad?',                       'descripcion' => 'Aplica para cargos con contacto directo con el cliente.'],
            ['orden' => 8,  'pregunta' => '¿Cumple con las normas de higiene, presentación e inocuidad?',         'descripcion' => 'Presentación personal adecuada y cumplimiento de normas sanitarias.'],
            ['orden' => 9,  'pregunta' => '¿Maneja adecuadamente los recursos, equipos y materiales?',            'descripcion' => 'Cuida el equipo y utiliza los recursos de forma responsable.'],
            ['orden' => 10, 'pregunta' => '¿Recomendarías contratar definitivamente a este colaborador?',         'descripcion' => 'Valoración general del evaluador sobre la continuidad del empleado.'],
        ]);
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('periodos_prueba_criterios');
    }
};
