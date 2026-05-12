<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('brew_ingredientes', function (Blueprint $table) {
            $table->id();
            // 'malta' | 'lupulo' | 'cerveza'
            $table->string('tipo', 20)->index();
            $table->string('codigo', 50)->nullable();
            $table->string('nombre', 200);
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('brew_ingredientes');
    }
};
