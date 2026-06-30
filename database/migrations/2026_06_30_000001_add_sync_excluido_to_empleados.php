<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
            $table->boolean('sync_excluido')->default(false)->after('activo');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
            $table->dropColumn('sync_excluido');
        });
    }
};
