<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('ventas_ordenes', function (Blueprint $table) {
            $table->date('fecha_facturacion')->nullable()->after('notas');
            $table->date('fecha_entrega')->nullable()->after('fecha_facturacion');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('ventas_ordenes', function (Blueprint $table) {
            $table->dropColumn(['fecha_facturacion', 'fecha_entrega']);
        });
    }
};
