<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('ausencias_injustificadas', function (Blueprint $table) {
            $table->unsignedBigInteger('cubierta_por_incapacidad_id')->nullable()->after('descripcion');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('ausencias_injustificadas', function (Blueprint $table) {
            $table->dropColumn('cubierta_por_incapacidad_id');
        });
    }
};
