<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Agrega tipo_contribuyente_id a proveedores.
     * 0 = No Inscrito (Factura consumidor final)
     * 1 = Contribuyente inscrito IVA (CCF, tiene NRC)
     * 2 = Gran Contribuyente (régimen especial DGII)
     */
    public function up(): void
    {
        if (Schema::connection('pagos')->hasTable('proveedores')) {
            Schema::connection('pagos')->table('proveedores', function (Blueprint $table) {
                if (!Schema::connection('pagos')->hasColumn('proveedores', 'tipo_contribuyente_id')) {
                    $table->unsignedBigInteger('tipo_contribuyente_id')->nullable()->after('tipo_persona_id');
                    $table->foreign('tipo_contribuyente_id', 'prov_tipo_contrib_fk')
                          ->references('id')->on('contribuyentes')
                          ->nullOnDelete();
                }
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('pagos')->hasTable('proveedores')) {
            Schema::connection('pagos')->table('proveedores', function (Blueprint $table) {
                $table->dropForeign('prov_tipo_contrib_fk');
                $table->dropColumn('tipo_contribuyente_id');
            });
        }
    }
};
