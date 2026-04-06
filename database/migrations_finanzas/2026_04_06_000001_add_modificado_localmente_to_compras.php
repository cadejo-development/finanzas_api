<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la bandera 'modificado_localmente' a recetas y productos.
 *
 * Cuando el usuario edita un registro en el sistema (vía API), se marca
 * modificado_localmente = true.  El comando compras:sync-origen respeta
 * esta bandera y NO sobreescribe esos registros durante la sincronización
 * con SQL Server.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::connection('compras')->hasTable('recetas') &&
            !Schema::connection('compras')->hasColumn('recetas', 'modificado_localmente')) {
            Schema::connection('compras')->table('recetas', function (Blueprint $table) {
                $table->boolean('modificado_localmente')->default(false)->after('activa')
                      ->comment('true = editado por usuario local; el sync de SQL Server no lo sobreescribe');
            });
        }

        if (Schema::connection('compras')->hasTable('productos') &&
            !Schema::connection('compras')->hasColumn('productos', 'modificado_localmente')) {
            Schema::connection('compras')->table('productos', function (Blueprint $table) {
                $table->boolean('modificado_localmente')->default(false)->after('activo')
                      ->comment('true = editado por usuario local; el sync de SQL Server no lo sobreescribe');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('compras')->hasTable('recetas')) {
            Schema::connection('compras')->table('recetas', function (Blueprint $table) {
                $table->dropColumn('modificado_localmente');
            });
        }
        if (Schema::connection('compras')->hasTable('productos')) {
            Schema::connection('compras')->table('productos', function (Blueprint $table) {
                $table->dropColumn('modificado_localmente');
            });
        }
    }
};
