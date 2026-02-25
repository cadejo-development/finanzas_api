    //
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
        Schema::connection('pagos')->create('solicitud_pago_adjuntos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('solicitud_pago_id')->constrained('solicitudes_pago');
            $table->string('nombre_archivo');
            $table->string('url');
            $table->string('tipo');
            $table->string('aud_usuario')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pagos')->dropIfExists('solicitud_pago_adjuntos');
    }
};
