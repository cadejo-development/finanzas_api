<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_recetas', function (Blueprint $table) {
            $table->string('brewer', 100)->nullable()->after('estilo');
            $table->string('version', 20)->nullable()->after('brewer');
            $table->date('fecha_receta')->nullable()->after('version');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_recetas', function (Blueprint $table) {
            $table->dropColumn(['brewer', 'version', 'fecha_receta']);
        });
    }
};
