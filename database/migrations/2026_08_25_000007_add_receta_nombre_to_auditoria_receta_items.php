<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        if (!Schema::connection('compras')->hasColumn('auditoria_receta_items', 'receta_nombre')) {
            Schema::connection('compras')->table('auditoria_receta_items', function (Blueprint $table) {
                $table->string('receta_nombre', 300)->nullable()->after('responsable_nombre');
            });
        }

        // Backfill receta_nombre desde la tabla recetas
        DB::connection('compras')->statement('
            UPDATE auditoria_receta_items i
            SET receta_nombre = r.nombre
            FROM recetas r
            WHERE i.receta_id = r.id
              AND i.receta_nombre IS NULL
        ');
    }

    public function down(): void
    {
        Schema::connection('compras')->table('auditoria_receta_items', function (Blueprint $table) {
            $table->dropColumn('receta_nombre');
        });
    }
};
