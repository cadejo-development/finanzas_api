<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection$connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('planilla_acreedores', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 150);
            $table->string('tipo', 50)->default('otro'); // banco|comercio|judicial|otro
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('planilla_acreedores');
    }
};
