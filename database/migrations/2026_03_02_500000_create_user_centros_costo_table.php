<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula usuarios (pgsql) con los centros de costo que administran.
 * Un gerente de sucursal puede tener uno o varios centros de costo asignados.
 */
return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        if (!Schema::connection('pgsql')->hasTable('user_centros_costo')) {
            Schema::connection('pgsql')->create('user_centros_costo', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('user_id');
                $table->string('centro_costo_codigo', 20);
                $table->string('aud_usuario')->nullable();
                $table->timestamps();

                $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                $table->foreign('centro_costo_codigo')->references('codigo')->on('centros_costo')->onDelete('cascade');
                $table->unique(['user_id', 'centro_costo_codigo']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('user_centros_costo');
    }
};
