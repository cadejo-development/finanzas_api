<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega columna unidad a pedido_detalle en la conexión 'compras'.
     * La unidad se almacena desde la receta (oz, lb, u, etc.) en el momento
     * que se aplica la receta al pedido, garantizando que refleje la unidad
     * de medida correcta del ingrediente y no la unidad de compra del catálogo.
     */
    public function up(): void
    {
        if (Schema::connection('compras')->hasTable('pedido_detalle')) {
            Schema::connection('compras')->table('pedido_detalle', function (Blueprint $table) {
                if (!Schema::connection('compras')->hasColumn('pedido_detalle', 'unidad')) {
                    $table->string('unidad', 20)->nullable()->after('cantidad');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('compras')->hasTable('pedido_detalle')) {
            Schema::connection('compras')->table('pedido_detalle', function (Blueprint $table) {
                $table->dropColumn('unidad');
            });
        }
    }
};
