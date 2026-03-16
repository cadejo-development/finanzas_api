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
        if (!Schema::connection('rrhh')->hasTable('tipos_permiso')) {
            Schema::connection('rrhh')->create('tipos_permiso', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 30)->unique();
                $table->string('nombre', 100);
                // categoria: personal | especial | sin_goce
                $table->string('categoria', 20);
                // max_dias: null = sin límite definido en el catálogo (ej. maternidad se rige por ley)
                $table->decimal('max_dias', 5, 1)->nullable();
                $table->boolean('permite_horas')->default(true)->comment('Si puede solicitarse por horas parciales');
                $table->boolean('activo')->default(true);
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });

            // Seed inicial
            DB::connection('rrhh')->table('tipos_permiso')->insert([
                // Personales
                ['codigo' => 'PERSONAL',           'nombre' => 'Permiso Personal',              'categoria' => 'personal',   'max_dias' => 5,    'permite_horas' => true,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                // Especiales
                ['codigo' => 'MATERNIDAD',         'nombre' => 'Permiso por Maternidad',         'categoria' => 'especial',   'max_dias' => 84,   'permite_horas' => false, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'PATERNIDAD',         'nombre' => 'Permiso por Paternidad',         'categoria' => 'especial',   'max_dias' => 3,    'permite_horas' => false, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'MATRIMONIO',         'nombre' => 'Permiso por Matrimonio',         'categoria' => 'especial',   'max_dias' => 3,    'permite_horas' => false, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'FALLECIMIENTO',      'nombre' => 'Permiso por Fallecimiento Familiar', 'categoria' => 'especial', 'max_dias' => 3, 'permite_horas' => false, 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                // Sin goce
                ['codigo' => 'SIN_GOCE',           'nombre' => 'Permiso Sin Goce de Sueldo',    'categoria' => 'sin_goce',   'max_dias' => null, 'permite_horas' => true,  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('tipos_permiso');
    }
};
