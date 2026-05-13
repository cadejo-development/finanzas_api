<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    /**
     * Make cantidad_kg (maltas) and cantidad_g (lupulos, levaduras, minerales) nullable
     * so that draft recipes can be saved without all quantities filled in.
     */
    public function up(): void
    {
        Schema::connection('compras')->table('brew_receta_maltas', function (Blueprint $table) {
            $table->decimal('cantidad_kg', 8, 3)->nullable()->change();
        });

        Schema::connection('compras')->table('brew_receta_lupulos', function (Blueprint $table) {
            $table->decimal('cantidad_g', 8, 2)->nullable()->change();
        });

        Schema::connection('compras')->table('brew_receta_levaduras', function (Blueprint $table) {
            $table->decimal('cantidad_g', 8, 2)->nullable()->change();
        });

        Schema::connection('compras')->table('brew_receta_minerales', function (Blueprint $table) {
            $table->decimal('cantidad_g', 8, 3)->nullable()->change();
        });
    }

    public function down(): void
    {
        // Note: reverting nullable→not-null could fail if there are null values in DB.
        Schema::connection('compras')->table('brew_receta_maltas', function (Blueprint $table) {
            $table->decimal('cantidad_kg', 8, 3)->nullable(false)->default(0)->change();
        });

        Schema::connection('compras')->table('brew_receta_lupulos', function (Blueprint $table) {
            $table->decimal('cantidad_g', 8, 2)->nullable(false)->default(0)->change();
        });

        Schema::connection('compras')->table('brew_receta_levaduras', function (Blueprint $table) {
            $table->decimal('cantidad_g', 8, 2)->nullable(false)->default(0)->change();
        });

        Schema::connection('compras')->table('brew_receta_minerales', function (Blueprint $table) {
            $table->decimal('cantidad_g', 8, 3)->nullable(false)->default(0)->change();
        });
    }
};
