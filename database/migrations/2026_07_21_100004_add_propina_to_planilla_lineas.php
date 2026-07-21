<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('planilla_lineas', function (Blueprint $table) {
            $table->decimal('propina', 10, 2)->default(0)->after('bonificaciones');
            $table->unsignedBigInteger('propina_detalle_id')->nullable()->after('propina');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('planilla_lineas', function (Blueprint $table) {
            $table->dropColumn(['propina', 'propina_detalle_id']);
        });
    }
};
