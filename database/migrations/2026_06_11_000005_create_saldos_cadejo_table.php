<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->create('saldos_cadejo', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->unsignedSmallInteger('anio');
            $table->decimal('dias_disponibles', 3, 1)->default(3);
            $table->decimal('dias_usados',      3, 1)->default(0);
            $table->string('aud_usuario')->nullable();
            $table->timestamps();

            $table->unique(['empleado_id', 'anio']);
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('saldos_cadejo');
    }
};
