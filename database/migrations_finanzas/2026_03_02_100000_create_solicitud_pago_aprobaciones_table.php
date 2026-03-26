<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Agregar columnas a solicitudes_pago usando SQL nativo de PG (IF NOT EXISTS
        // es imbatible: nunca falla aunque la columna ya exista)
        DB::connection('pagos')->statement('
            ALTER TABLE solicitudes_pago
            ADD COLUMN IF NOT EXISTS solicitante_id BIGINT NULL
        ');
        DB::connection('pagos')->statement('
            ALTER TABLE solicitudes_pago
            ADD COLUMN IF NOT EXISTS solicitante_nombre VARCHAR(150) NULL
        ');

        // Tabla de líneas de aprobación por solicitud
        if (!Schema::connection('pagos')->hasTable('solicitud_pago_aprobaciones')) {
            Schema::connection('pagos')->create('solicitud_pago_aprobaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('solicitud_pago_id');
            $table->foreign('solicitud_pago_id')
                  ->references('id')
                  ->on('solicitudes_pago')
                  ->onDelete('cascade');

            // Orden de la etapa (0 = visto bueno, 1-4 = niveles de aprobación)
            $table->unsignedTinyInteger('nivel_orden')->default(0);
            // Código descriptivo de la etapa
            $table->string('nivel_codigo', 30); // visto_bueno, nivel_1, nivel_2...
            // Rol requerido para aprobar (código del rol en pgsql)
            $table->string('rol_requerido', 60);

            // Quién aprobó/rechazó (user_id en pgsql, sin FK cross-db)
            $table->unsignedBigInteger('aprobador_id')->nullable();
            $table->string('aprobador_nombre', 150)->nullable();

            // Estado de esta línea
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado', 'cancelado'])
                  ->default('pendiente');
            $table->text('comentario')->nullable();
            $table->timestamp('aprobado_en')->nullable();

            $table->string('aud_usuario')->nullable();
            $table->timestamps();

            // Índices de búsqueda frecuente
            $table->index(['solicitud_pago_id', 'nivel_orden']);
            $table->index(['rol_requerido', 'estado']);
            });
        } // end if !hasTable
    }

    public function down(): void
    {
        Schema::connection('pagos')->dropIfExists('solicitud_pago_aprobaciones');
    }
};
