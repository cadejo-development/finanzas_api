<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->string('tipo_receta', 20)->default('plato')->after('tipo');
            $table->text('instrucciones')->nullable()->after('descripcion');
            $table->string('foto_plato', 500)->nullable()->after('instrucciones');
            $table->string('foto_plateria', 500)->nullable()->after('foto_plato');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->dropColumn(['tipo_receta', 'instrucciones', 'foto_plato', 'foto_plateria']);
        });
    }
};
