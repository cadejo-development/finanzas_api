<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        if (!Schema::connection('rrhh')->hasTable('saldos_vacaciones')) {
            Schema::connection('rrhh')->create('saldos_vacaciones', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('empleado_id');
                $table->smallInteger('anio');
                $table->decimal('dias_disponibles', 5, 1)->default(15)->comment('Días ganados en el año');
                $table->decimal('dias_usados', 5, 1)->default(0);
                $table->decimal('dias_acumulados', 5, 1)->default(0)->comment('Días arrastrados de años anteriores (máx 30 total)');
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();

                $table->unique(['empleado_id', 'anio']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('saldos_vacaciones');
    }
};
