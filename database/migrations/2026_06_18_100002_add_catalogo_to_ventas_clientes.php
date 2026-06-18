<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('ventas_clientes', function (Blueprint $table) {
            $table->foreignId('catalogo_precio_id')
                  ->nullable()
                  ->after('activo')
                  ->constrained('ventas_catalogos_precio')
                  ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('ventas_clientes', function (Blueprint $table) {
            $table->dropForeign(['catalogo_precio_id']);
            $table->dropColumn('catalogo_precio_id');
        });
    }
};
