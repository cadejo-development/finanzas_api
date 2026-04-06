<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('productos', function (Blueprint $table) {
            // Unidad física de referencia para recetas (ej: 'g', 'ml', 'lb').
            // Solo aplica cuando la unidad de compra es un empaque (caja, paquete, botella, etc.).
            $table->string('unidad_base', 20)->nullable()->after('unidad');

            // Cuántas unidades_base contiene 1 unidad de compra.
            // Ej: 1 caja de mayonesa = 4500 g → factor_conversion = 4500
            $table->decimal('factor_conversion', 12, 4)->nullable()->after('unidad_base');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('productos', function (Blueprint $table) {
            $table->dropColumn(['unidad_base', 'factor_conversion']);
        });
    }
};
