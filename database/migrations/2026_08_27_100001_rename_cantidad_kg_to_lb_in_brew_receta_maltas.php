<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * La columna se creó como cantidad_kg pero el modelo y el frontend usan cantidad_lb.
 * Se renombra para que los INSERT de maltas no fallen con "column cantidad_lb does not exist".
 */
return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_receta_maltas', function (Blueprint $table) {
            $table->renameColumn('cantidad_kg', 'cantidad_lb');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_receta_maltas', function (Blueprint $table) {
            $table->renameColumn('cantidad_lb', 'cantidad_kg');
        });
    }
};
