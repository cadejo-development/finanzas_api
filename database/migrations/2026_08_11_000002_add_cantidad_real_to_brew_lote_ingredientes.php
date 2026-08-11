<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_lote_ingredientes', function (Blueprint $table) {
            $table->decimal('cantidad_real', 10, 3)->nullable()->after('unidad');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_ingredientes', function (Blueprint $table) {
            $table->dropColumn('cantidad_real');
        });
    }
};
