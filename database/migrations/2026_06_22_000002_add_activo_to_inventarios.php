<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('inventarios', function (Blueprint $table) {
            if (!Schema::connection('compras')->hasColumn('inventarios', 'activo')) {
                $table->boolean('activo')->default(true)->after('seccion');
            }
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('inventarios', function (Blueprint $table) {
            if (Schema::connection('compras')->hasColumn('inventarios', 'activo')) {
                $table->dropColumn('activo');
            }
        });
    }
};
