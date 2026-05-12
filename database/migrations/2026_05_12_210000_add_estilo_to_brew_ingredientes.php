<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_ingredientes', function (Blueprint $table) {
            $table->string('estilo', 100)->nullable()->after('nombre');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_ingredientes', function (Blueprint $table) {
            $table->dropColumn('estilo');
        });
    }
};
