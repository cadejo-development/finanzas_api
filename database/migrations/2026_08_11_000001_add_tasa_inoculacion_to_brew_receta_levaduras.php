<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_receta_levaduras', function (Blueprint $table) {
            $table->decimal('tasa_inoculacion_millones', 8, 3)->default(1.0)->after('temp_max');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_receta_levaduras', function (Blueprint $table) {
            $table->dropColumn('tasa_inoculacion_millones');
        });
    }
};
