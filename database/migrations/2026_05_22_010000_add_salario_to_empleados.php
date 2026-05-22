<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
            $table->decimal('salario_base', 10, 2)->nullable()->after('cargo_id');
        });
    }

    public function down(): void
    {
        Schema::connection('pgsql')->table('empleados', function (Blueprint $table) {
            $table->dropColumn('salario_base');
        });
    }
};
