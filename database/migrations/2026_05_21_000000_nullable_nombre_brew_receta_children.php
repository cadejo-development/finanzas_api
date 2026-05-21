<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = [
            'brew_receta_maltas',
            'brew_receta_lupulos',
            'brew_receta_minerales',
            'brew_receta_levaduras',
        ];

        foreach ($tables as $table) {
            Schema::connection('compras')->table($table, function (Blueprint $t) {
                $t->string('nombre')->nullable()->change();
            });
        }
    }

    public function down(): void
    {
        // No revertir — podría fallar si hay filas con null
    }
};
