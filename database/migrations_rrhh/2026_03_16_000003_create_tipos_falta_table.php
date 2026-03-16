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
        if (!Schema::connection('rrhh')->hasTable('tipos_falta')) {
            Schema::connection('rrhh')->create('tipos_falta', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 30)->unique();
                $table->string('nombre', 100);
                // gravedad: leve | grave
                $table->string('gravedad', 10);
                $table->boolean('activo')->default(true);
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();
            });

            DB::connection('rrhh')->table('tipos_falta')->insert([
                ['codigo' => 'FALTA_LEVE',  'nombre' => 'Falta Leve',  'gravedad' => 'leve',  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
                ['codigo' => 'FALTA_GRAVE', 'nombre' => 'Falta Grave', 'gravedad' => 'grave', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('tipos_falta');
    }
};
