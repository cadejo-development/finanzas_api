<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->create('bonificaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('empleado_id')->constrained('empleados');
            $table->foreignId('tipo_bonificacion_id')->constrained('tipos_bonificacion');
            $table->foreignId('estado_id')->constrained('estados_bonificacion');
            $table->foreignId('solicitado_por_id')->constrained('empleados');
            $table->foreignId('aprobado_por_id')->nullable()->constrained('empleados');
            $table->decimal('monto', 10, 2);
            $table->text('descripcion')->nullable();
            $table->date('fecha_aplicar');   // quincena en que se pagará
            $table->text('notas_aprobacion')->nullable();
            $table->string('aud_usuario', 100)->default('sistema');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->dropIfExists('bonificaciones');
    }
};
