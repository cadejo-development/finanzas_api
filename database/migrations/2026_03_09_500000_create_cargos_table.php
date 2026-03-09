<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de cargos (se pobla desde SQL Server via script migrate_empleados.js).
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        Schema::connection('pgsql')->create('cargos', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();   // crgCodigo de SQL Server
            $table->string('nombre', 120);            // crgNombre
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('cargos');
    }
};
