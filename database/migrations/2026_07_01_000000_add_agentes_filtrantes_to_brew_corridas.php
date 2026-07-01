<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::connection('compras')->table('brew_lote_filtracion_corridas', function (Blueprint $table) {
            $table->decimal('tierra_blanca', 8, 3)->nullable()->after('vol_litros');
            $table->decimal('tierra_roja',   8, 3)->nullable()->after('tierra_blanca');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_filtracion_corridas', function (Blueprint $table) {
            $table->dropColumn(['tierra_blanca', 'tierra_roja']);
        });
    }
};
