<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_lote_llenado_botellas_corridas', function (Blueprint $table) {
            $table->integer('botellas_peracético')->nullable()->default(0)
                ->after('bbt_vol_fin')
                ->comment('Botellas descartadas por purga de peracético (1a=48, 2a=24, 3a=12)');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_llenado_botellas_corridas', function (Blueprint $table) {
            $table->dropColumn('botellas_peracético');
        });
    }
};
