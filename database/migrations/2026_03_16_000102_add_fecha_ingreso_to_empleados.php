<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        if (!Schema::connection('pgsql')->hasColumn('empleados', 'fecha_ingreso')) {
            Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
                $table->date('fecha_ingreso')->nullable()->after('activo');
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('pgsql')->hasColumn('empleados', 'fecha_ingreso')) {
            Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
                $table->dropColumn('fecha_ingreso');
            });
        }
    }
};
