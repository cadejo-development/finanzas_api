<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            // Indica que el registro fue editado manualmente en el sistema.
            // Los scripts de sync respetan este flag y NO sobreescriben el registro.
            $table->boolean('modificado_localmente')->default(false)->after('activa');
        });

        Schema::connection('compras')->table('productos', function (Blueprint $table) {
            $table->boolean('modificado_localmente')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->dropColumn('modificado_localmente');
        });

        Schema::connection('compras')->table('productos', function (Blueprint $table) {
            $table->dropColumn('modificado_localmente');
        });
    }
};
