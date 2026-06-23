<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('auditoria_criterios', function (Blueprint $table) {
            $table->string('tipo', 20)->default('operaciones')->after('id');
        });

        // Marcar los criterios existentes como tipo 'operaciones'
        DB::connection('compras')->table('auditoria_criterios')->update(['tipo' => 'operaciones']);
    }

    public function down(): void
    {
        Schema::connection('compras')->table('auditoria_criterios', function (Blueprint $table) {
            $table->dropColumn('tipo');
        });
    }
};
