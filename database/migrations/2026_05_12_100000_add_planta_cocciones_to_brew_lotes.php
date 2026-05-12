<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_lotes', function (Blueprint $table) {
            $table->string('planta', 20)->default('sivar')->after('estado')
                ->comment('sivar = Planta Sivar, zr = Planta ZR');
            $table->unsignedTinyInteger('num_cocciones')->default(1)->after('planta')
                ->comment('Número de cocciones que conforman este lote (múltiples cocciones a una fermentadora)');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lotes', function (Blueprint $table) {
            $table->dropColumn(['planta', 'num_cocciones']);
        });
    }
};
