<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        if (!Schema::connection('pgsql')->hasColumn('empleados', 'departamento_id')) {
            Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
                $table->unsignedBigInteger('departamento_id')->nullable()->after('sucursal_id');
                $table->foreign('departamento_id', 'emp_departamento_fk')
                    ->references('id')->on('departamentos')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        if (Schema::connection('pgsql')->hasColumn('empleados', 'departamento_id')) {
            Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
                $table->dropForeign('emp_departamento_fk');
                $table->dropColumn('departamento_id');
            });
        }
    }
};
