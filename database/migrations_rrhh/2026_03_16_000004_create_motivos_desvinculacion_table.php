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
        if (!Schema::connection('rrhh')->hasTable('motivos_desvinculacion')) {
            Schema::connection('rrhh')->create('motivos_desvinculacion', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 30)->unique();
                $table->string('nombre', 150);
                // tipo: despido | renuncia
                $table->string('tipo', 10);
                $table->boolean('activo')->default(true);
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });

            DB::connection('rrhh')->table('motivos_desvinculacion')->insert([
                // Despidos
                ['codigo' => 'INASISTENCIA',      'nombre' => 'Inasistencias Injustificadas',   'tipo' => 'despido', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'BAJO_RENDIMIENTO',  'nombre' => 'Bajo Rendimiento',               'tipo' => 'despido', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'MALA_CONDUCTA',     'nombre' => 'Mala Conducta',                  'tipo' => 'despido', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'FRAUDE',            'nombre' => 'Fraude o Deshonestidad',          'tipo' => 'despido', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'ABANDONO',          'nombre' => 'Abandono de Puesto',              'tipo' => 'despido', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                // Renuncias
                ['codigo' => 'PERSONAL',          'nombre' => 'Razones Personales',             'tipo' => 'renuncia', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'MEJOR_OFERTA',      'nombre' => 'Mejor Oferta Laboral',           'tipo' => 'renuncia', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'REUBICACION',       'nombre' => 'Reubicación Geográfica',         'tipo' => 'renuncia', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'ESTUDIOS',          'nombre' => 'Continuación de Estudios',       'tipo' => 'renuncia', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'SALUD',             'nombre' => 'Motivos de Salud',               'tipo' => 'renuncia', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('motivos_desvinculacion');
    }
};
