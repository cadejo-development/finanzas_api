<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'vol_preboil_obj'))
                $table->decimal('vol_preboil_obj', 8, 2)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'vol_postboil_obj'))
                $table->decimal('vol_postboil_obj', 8, 2)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->dropColumn(['vol_preboil_obj', 'vol_postboil_obj']);
        });
    }
};
