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
            $table->string('tipo', 20)->default('operaciones')->after('id');
            $table->unsignedInteger('respondido_por_id')->nullable()->after('aud_usuario');
            $table->string('respondido_por_nombre', 200)->nullable()->after('respondido_por_id');
            $table->timestamp('respondido_at')->nullable()->after('respondido_por_nombre');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('auditorias_receta', function (Blueprint $table) {
            $table->dropColumn(['tipo', 'respondido_por_id', 'respondido_por_nombre', 'respondido_at']);
        });
    }
};
