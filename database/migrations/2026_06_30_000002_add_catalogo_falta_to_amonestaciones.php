<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            $table->string('catalogo_falta', 255)->nullable()->after('tipo_falta_id');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            $table->dropColumn('catalogo_falta');
        });
    }
};
