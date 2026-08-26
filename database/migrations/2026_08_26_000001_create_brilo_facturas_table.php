<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pagos';

    public function up(): void
    {
        Schema::connection('pagos')->create('brilo_facturas', function (Blueprint $table) {
            $table->id();
            $table->integer('mco_id')->unique();          // PK en Brilo — garantiza idempotencia
            $table->date('fecha_doc')->nullable();         // mcoFecha
            $table->timestampTz('fecha_creado')->nullable(); // mcoFechaHoraCreado
            $table->string('tipo_doc', 20)->nullable();   // CCF | FAC | etc.
            $table->string('num_doc', 150)->nullable();
            $table->text('concepto')->nullable();
            $table->integer('suc_id_brilo')->nullable();
            $table->string('sucursal_nombre', 150)->nullable();
            $table->integer('ceco_id')->nullable();
            $table->string('ceco_nombre', 150)->nullable();
            $table->integer('ceco_sub_id')->nullable();
            $table->string('ceco_sub_nombre', 150)->nullable();
            $table->integer('prv_id')->nullable();
            $table->string('prv_nombre', 250)->nullable();
            $table->decimal('monto_afecto', 15, 2)->default(0);
            $table->decimal('monto_exento', 15, 2)->default(0);
            $table->timestampTz('synced_at')->nullable();
            $table->timestamps();

            $table->index('fecha_doc');
            $table->index('ceco_id');
            $table->index('suc_id_brilo');
        });
    }

    public function down(): void
    {
        Schema::connection('pagos')->dropIfExists('brilo_facturas');
    }
};
