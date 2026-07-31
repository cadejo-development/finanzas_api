<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('pedido_detalle', function (Blueprint $table) {
            $table->text('justificacion')->nullable()->after('nota');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('pedido_detalle', function (Blueprint $table) {
            $table->dropColumn('justificacion');
        });
    }
};
