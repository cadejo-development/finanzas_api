<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'pagos'; }

    public function up(): void
    {
        if (!Schema::connection('pagos')->hasTable('tipos_persona')) {
            Schema::connection('pagos')->create('tipos_persona', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 20)->unique();   // NAT, JUR
                $table->string('nombre', 100);            // Natural, Jurídica
                $table->boolean('activo')->default(true);
                $table->timestamps();
            });
        }

        // Sembrar los dos valores base
        DB::connection('pagos')->table('tipos_persona')->insertOrIgnore([
            ['id' => 1, 'codigo' => 'NAT', 'nombre' => 'Natural',  'activo' => true, 'created_at' => now(), 'updated_at' => now()],
            ['id' => 2, 'codigo' => 'JUR', 'nombre' => 'Jurídica', 'activo' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    public function down(): void
    {
        Schema::connection('pagos')->dropIfExists('tipos_persona');
    }
};
