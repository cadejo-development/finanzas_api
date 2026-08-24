<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            // Cronómetro general del proceso (inicia molienda, para al iniciar enfriamiento)
            $table->string('hora_inicio_general', 8)->nullable()->after('enf_hora_fin');
            $table->string('hora_fin_general', 8)->nullable()->after('hora_inicio_general');
        });

        // Ampliar oxig_nivel: el campo es texto plano libre, 30 chars es muy corto
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->string('oxig_nivel', 200)->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->dropColumn(['hora_inicio_general', 'hora_fin_general']);
            $table->string('oxig_nivel', 30)->nullable()->change();
        });
    }
};
