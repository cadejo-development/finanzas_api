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
        if (!Schema::connection('pagos')->hasTable('estados_solicitud_pago')) {
            Schema::connection('pagos')->create('estados_solicitud_pago', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 20)->unique();
                $table->string('nombre', 50)->unique();
                $table->string('descripcion', 150)->nullable();
                $table->timestamps();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pagos')->dropIfExists('estados_solicitud_pago');
    }
};
