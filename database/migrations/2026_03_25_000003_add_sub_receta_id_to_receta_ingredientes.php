<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('receta_ingredientes', function (Blueprint $table) {
            $table->unsignedBigInteger('sub_receta_id')->nullable()->after('producto_id');
            $table->foreign('sub_receta_id')->references('id')->on('recetas')->nullOnDelete();
            // producto_id pasa a ser nullable (puede ser MP o sub-receta)
            $table->unsignedBigInteger('producto_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('receta_ingredientes', function (Blueprint $table) {
            $table->dropForeign(['sub_receta_id']);
            $table->dropColumn('sub_receta_id');
            $table->unsignedBigInteger('producto_id')->nullable(false)->change();
        });
    }
};
