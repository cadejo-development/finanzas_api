<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('plantillas_contrato', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tipo_contrato_id');
            // Cargo opcional — referencia al catálogo pgsql.cargos, sin FK cross-DB
            $table->unsignedBigInteger('cargo_id')->nullable();
            $table->string('cargo_nombre', 120)->nullable(); // desnormalizado
            $table->string('nombre', 200);
            $table->longText('contenido'); // HTML con variables {{nombre}}, {{cargo}}, etc.
            $table->unsignedSmallInteger('version')->default(1);
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->foreign('tipo_contrato_id')
                  ->references('id')
                  ->on('tipos_contrato')
                  ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('plantillas_contrato');
    }
};
