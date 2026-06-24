<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('auditorias_receta', function (Blueprint $table) {
            $table->text('comentario_gerente')->nullable()->after('acciones_correctivas');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('auditorias_receta', function (Blueprint $table) {
            $table->dropColumn('comentario_gerente');
        });
    }
};
