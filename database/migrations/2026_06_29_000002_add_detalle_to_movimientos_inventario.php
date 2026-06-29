<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->table('movimientos_inventario', function (Blueprint $table) {
            $table->jsonb('detalle')->nullable()->after('referencia_tipo');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('movimientos_inventario', function (Blueprint $table) {
            $table->dropColumn('detalle');
        });
    }
};
