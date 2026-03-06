<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega columna codigo_origen a productos y recetas en la conexión 'compras'.
 * Sirve para rastrear el proCodigo original del sistema SQL Server de origen.
 */
return new class extends Migration
{
    public function up(): void
    {
        // productos: agregar codigo_origen si no existe
        if (Schema::connection('compras')->hasTable('productos')) {
            Schema::connection('compras')->table('productos', function (Blueprint $table) {
                if (!Schema::connection('compras')->hasColumn('productos', 'codigo_origen')) {
                    $table->string('codigo_origen', 50)->nullable()->unique()->after('codigo')
                          ->comment('Código original del sistema de origen (SQL Server proCodigo)');
                } else {
                    // Agregar unique constraint si la columna existe pero sin constraint
                    try {
                        $table->unique('codigo_origen', 'productos_codigo_origen_unique');
                    } catch (\Exception $e) { /* ya existe */ }
                }
                if (!Schema::connection('compras')->hasColumn('productos', 'costo')) {
                    $table->decimal('costo', 12, 4)->default(0)->after('precio')
                          ->comment('Costo de producción/compra del ingrediente');
                }
            });
        }

        // recetas: agregar codigo_origen y tipo si no existen
        if (Schema::connection('compras')->hasTable('recetas')) {
            Schema::connection('compras')->table('recetas', function (Blueprint $table) {
                if (!Schema::connection('compras')->hasColumn('recetas', 'codigo_origen')) {
                    $table->string('codigo_origen', 50)->nullable()->unique()->after('nombre')
                          ->comment('Código original del sistema de origen (SQL Server proCodigo)');
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('compras')->hasTable('productos')) {
            Schema::connection('compras')->table('productos', function (Blueprint $table) {
                $table->dropColumn(['codigo_origen', 'costo']);
            });
        }

        if (Schema::connection('compras')->hasTable('recetas')) {
            Schema::connection('compras')->table('recetas', function (Blueprint $table) {
                $table->dropColumn('codigo_origen');
            });
        }
    }
};
