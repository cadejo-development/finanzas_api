<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
          Schema::connection('pagos')->create('solicitud_pago_detalles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_pago_id')
                ->constrained('solicitudes_pago');

            $table->text('concepto');

            $table->foreignId('centro_costo_id')
                ->constrained('centros_costo');

            $table->decimal('cantidad', 12, 2);
            $table->decimal('precio_unitario', 12, 2);
            $table->decimal('subtotal_linea', 12, 2);

            $table->string('aud_usuario')->nullable();
            $table->timestamps();
          });
    }

    public function down(): void
    {
        Schema::connection('pagos')->dropIfExists('solicitud_pago_detalles');
    }
};