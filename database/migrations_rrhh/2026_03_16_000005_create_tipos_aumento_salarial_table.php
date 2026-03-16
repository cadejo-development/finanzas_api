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
        if (!Schema::connection('rrhh')->hasTable('tipos_aumento_salarial')) {
            Schema::connection('rrhh')->create('tipos_aumento_salarial', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 30)->unique();
                $table->string('nombre', 100);
                $table->boolean('activo')->default(true);
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });

            DB::connection('rrhh')->table('tipos_aumento_salarial')->insert([
                ['codigo' => 'MERITO',      'nombre' => 'Por Mérito',          'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'ANUAL',       'nombre' => 'Aumento Anual',       'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'PROMOCION',   'nombre' => 'Por Promoción',       'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'NIVELACION',  'nombre' => 'Nivelación Salarial', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('tipos_aumento_salarial');
    }
};
