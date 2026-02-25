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
        Schema::connection('pagos')->create('solicitudes_pago', function (Blueprint $table) {
            $table->id();
            $table->string('codigo')->unique();
            $table->date('fecha_solicitud');
            $table->date('fecha_pago');
            $table->foreignId('forma_pago_id')->constrained('formas_pago');
            $table->foreignId('proveedor_id')->constrained('proveedores');
            $table->foreignId('contribuyente_id')->constrained('contribuyentes');
            $table->string('personeria');
            $table->boolean('es_servicio');
            $table->string('tipo_gasto');
            $table->string('estado')->default('BORRADOR');
            $table->integer('nivel_aprobacion')->nullable();
            $table->string('aprobador_asignado')->nullable();
            $table->decimal('sub_total', 12, 2)->default(0);
            $table->decimal('iva', 12, 2)->default(0);
            $table->decimal('ret_isr', 12, 2)->default(0);
            $table->decimal('perc_iva_1', 12, 2)->default(0);
            $table->decimal('a_pagar', 12, 2)->default(0);
            $table->string('aud_usuario')->nullable();
            $table->timestamps();
            $table->index(['estado', 'fecha_solicitud', 'proveedor_id', 'tipo_gasto']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::connection('pagos')->dropIfExists('solicitudes_pago');
    }
};
