<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        if (!Schema::connection('rrhh')->hasTable('tipos_incapacidad')) {
            Schema::connection('rrhh')->create('tipos_incapacidad', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 30)->unique();
                $table->string('nombre', 100);
                $table->boolean('activo')->default(true);
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });

            DB::connection('rrhh')->table('tipos_incapacidad')->insert([
                ['codigo' => 'ENFERMEDAD_COMUN',    'nombre' => 'Enfermedad Común',             'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'ACCIDENTE_TRABAJO',   'nombre' => 'Accidente de Trabajo',         'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'MATERNIDAD',          'nombre' => 'Maternidad',                   'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'HOMOLOGADA',          'nombre' => 'Incapacidad Homologada',       'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('tipos_incapacidad');
    }
};
