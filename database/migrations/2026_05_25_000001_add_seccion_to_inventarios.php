<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('inventarios', function (Blueprint $table) {
            $table->string('seccion', 50)->nullable()->after('stock_minimo');
            $table->index('seccion');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('inventarios', function (Blueprint $table) {
            $table->dropIndex(['seccion']);
            $table->dropColumn('seccion');
        });
    }
};
