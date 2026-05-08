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
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->boolean('sincronizado_brilo')->default(false)->after('modificado_localmente');
        });
        // Los datos se actualizan por separado con: php artisan brew:sync-brilo-flags
    }

    public function down(): void
    {
        Schema::connection('compras')->table('recetas', function (Blueprint $table) {
            $table->dropColumn('sincronizado_brilo');
        });
    }
};
