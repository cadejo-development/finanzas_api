<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('permisos', function (Blueprint $table) {
            $table->boolean('doc_posterior_pendiente')->default(false)->after('archivo_ruta');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('permisos', function (Blueprint $table) {
            $table->dropColumn('doc_posterior_pendiente');
        });
    }
};
