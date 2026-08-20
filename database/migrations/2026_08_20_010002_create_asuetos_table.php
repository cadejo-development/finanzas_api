<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('asuetos', function (Blueprint $table) {
            $table->id();
            $table->date('fecha');
            $table->string('nombre', 150);
            $table->string('tipo', 20)->default('nacional'); // nacional | departamental
            $table->unsignedSmallInteger('geo_departamento_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->string('creado_por', 150)->nullable();
            $table->timestamps();

            $table->foreign('geo_departamento_id')->references('id')->on('geo_departamentos')->nullOnDelete();
            $table->index(['fecha', 'activo']);
            $table->index(['tipo', 'geo_departamento_id']);
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('asuetos');
    }
};
