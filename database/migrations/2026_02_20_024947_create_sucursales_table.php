<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (config('database.default') !== 'pgsql' && (!isset($this->connection) || $this->connection !== 'pgsql')) {
            return;
        }
        Schema::connection('pgsql')->create('sucursales', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 20)->nullable();
            $table->string('nombre', 150);
            $table->boolean('activo')->default(true);
            $table->string('aud_usuario', 150)->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (config('database.default') !== 'pgsql' && (!isset($this->connection) || $this->connection !== 'pgsql')) {
            return;
        }
        Schema::dropIfExists('sucursales');
    }
};
