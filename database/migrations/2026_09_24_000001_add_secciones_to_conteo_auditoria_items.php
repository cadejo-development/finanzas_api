<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('conteo_auditoria_items', function (Blueprint $table) {
            $table->jsonb('secciones_conteo')->default('{}')->after('unidad');
            $table->jsonb('secciones_comprobadas')->default('{}')->after('secciones_conteo');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('conteo_auditoria_items', function (Blueprint $table) {
            $table->dropColumn(['secciones_conteo', 'secciones_comprobadas']);
        });
    }
};
