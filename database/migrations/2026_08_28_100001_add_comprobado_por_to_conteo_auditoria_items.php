<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->table('conteo_auditoria_items', function (Blueprint $table) {
            $table->string('comprobado_por')->nullable()->after('comprobado');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('conteo_auditoria_items', function (Blueprint $table) {
            $table->dropColumn('comprobado_por');
        });
    }
};
