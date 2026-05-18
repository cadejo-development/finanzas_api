<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->boolean('actualizada')->default(false)->after('modificado_localmente');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->dropColumn('actualizada');
        });
    }
};
