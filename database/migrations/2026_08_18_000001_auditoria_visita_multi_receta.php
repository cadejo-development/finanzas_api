<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Hacer nullable receta_id y tipo_receta en auditorias_receta
        //    (las auditorías nuevas son visitas de sucursal sin receta fija)
        DB::connection('compras')->statement("
            ALTER TABLE auditorias_receta
                ALTER COLUMN receta_id   DROP NOT NULL,
                ALTER COLUMN tipo_receta DROP NOT NULL
        ");

        // 2. Nueva tabla: auditoria_receta_items
        //    Cada fila = una receta evaluada dentro de una visita
        Schema::connection('compras')->create('auditoria_receta_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->bigInteger('auditoria_id')->index();
            $table->bigInteger('receta_id');
            $table->string('tipo_receta', 20)->default('plato');
            $table->bigInteger('estacion_id')->nullable();
            $table->integer('responsable_id')->nullable();
            $table->string('responsable_nombre', 200)->nullable();
            $table->decimal('calificacion', 5, 2)->nullable();
            $table->string('clasificacion', 20)->nullable();
            $table->text('notas')->nullable();
            $table->smallInteger('orden')->default(0);
            $table->timestamps();

            $table->foreign('auditoria_id')
                  ->references('id')->on('auditorias_receta')
                  ->onDelete('cascade');
        });

        // 3. Agregar receta_item_id a auditoria_items
        Schema::connection('compras')->table('auditoria_items', function (Blueprint $table) {
            $table->bigInteger('receta_item_id')->nullable()->after('auditoria_id')->index();
        });

        // 4. Agregar receta_item_id a auditoria_fotos
        Schema::connection('compras')->table('auditoria_fotos', function (Blueprint $table) {
            $table->bigInteger('receta_item_id')->nullable()->after('auditoria_id');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('auditoria_fotos', function (Blueprint $table) {
            $table->dropColumn('receta_item_id');
        });

        Schema::connection('compras')->table('auditoria_items', function (Blueprint $table) {
            $table->dropColumn('receta_item_id');
        });

        Schema::connection('compras')->dropIfExists('auditoria_receta_items');

        // No revertimos el NOT NULL en receta_id/tipo_receta porque puede haber datos con NULL
    }
};
