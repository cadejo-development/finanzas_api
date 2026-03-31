<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        Schema::connection('rrhh')->create('expediente_cuentas_banco', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('empleado_id');
            $table->string('banco', 100);
            $table->string('tipo_cuenta', 20)->default('ahorros'); // ahorros | corriente
            $table->string('numero_cuenta', 40);
            $table->string('titular', 120)->nullable();
            $table->boolean('es_principal')->default(false);
            $table->string('aud_usuario', 100)->nullable();
            $table->timestamps();

            $table->index('empleado_id');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->dropIfExists('expediente_cuentas_banco');
    }
};
