<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Catálogos geográficos de El Salvador — guardados en la DB principal (pgsql/core)
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('geo_departamentos', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->string('codigo', 2)->unique();   // 01–14
            $table->string('nombre', 80);
            $table->timestamps();
        });

        Schema::connection('pgsql')->create('geo_distritos', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->unsignedSmallInteger('departamento_id');
            $table->string('codigo', 6);             // e.g. 0501
            $table->string('nombre', 100);
            $table->timestamps();

            $table->index('departamento_id');
            $table->foreign('departamento_id')->references('id')->on('geo_departamentos')->onDelete('cascade');
        });

        Schema::connection('pgsql')->create('geo_municipios', function (Blueprint $table) {
            $table->smallIncrements('id');
            $table->unsignedSmallInteger('departamento_id');
            $table->unsignedSmallInteger('distrito_id');
            $table->string('codigo', 6)->nullable();
            $table->string('nombre', 100);
            $table->timestamps();

            $table->index('departamento_id');
            $table->index('distrito_id');
            $table->foreign('departamento_id')->references('id')->on('geo_departamentos')->onDelete('cascade');
            $table->foreign('distrito_id')->references('id')->on('geo_distritos')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('geo_municipios');
        Schema::connection('pgsql')->dropIfExists('geo_distritos');
        Schema::connection('pgsql')->dropIfExists('geo_departamentos');
    }
};
