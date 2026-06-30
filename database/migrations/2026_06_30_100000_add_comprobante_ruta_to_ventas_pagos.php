<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('ventas_pagos', function (Blueprint $table) {
            if (!Schema::connection('compras')->hasColumn('ventas_pagos', 'comprobante_ruta')) {
                $table->string('comprobante_ruta', 500)->nullable()->after('comprobante');
                $table->string('comprobante_nombre', 255)->nullable()->after('comprobante_ruta');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('ventas_pagos', function (Blueprint $table) {
            $table->dropColumn(['comprobante_ruta', 'comprobante_nombre']);
        });
    }
};
