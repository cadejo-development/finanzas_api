<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('inventarios', function (Blueprint $table) {
            $table->boolean('prod_seg')->default(false)->after('activo')
                  ->comment('Producto marcado para conteo físico diario por sucursal');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('inventarios', function (Blueprint $table) {
            $table->dropColumn('prod_seg');
        });
    }
};
