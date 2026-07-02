<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('ventas_catalogo_precio_lineas', function (Blueprint $table) {
            $table->unsignedSmallInteger('cantidad_minima')->default(1)->after('precio_con_iva');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('ventas_catalogo_precio_lineas', function (Blueprint $table) {
            $table->dropColumn('cantidad_minima');
        });
    }
};
