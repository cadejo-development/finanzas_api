<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            $table->string('token_aceptacion', 64)->nullable()->unique()->after('archivo_ruta');
            $table->boolean('acepta_empleado')->nullable()->after('token_aceptacion'); // null=pendiente, true=aceptó, false=rechazó
            $table->timestamp('fecha_aceptacion')->nullable()->after('acepta_empleado');
            $table->text('comentario_empleado')->nullable()->after('fecha_aceptacion');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            $table->dropColumn(['token_aceptacion', 'acepta_empleado', 'fecha_aceptacion', 'comentario_empleado']);
        });
    }
};
