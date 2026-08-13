<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'macerado_hora_inicio'))
                $table->string('macerado_hora_inicio', 8)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'macerado_hora_fin'))
                $table->string('macerado_hora_fin', 8)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'hervor_hora_inicio'))
                $table->string('hervor_hora_inicio', 8)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'hervor_hora_fin'))
                $table->string('hervor_hora_fin', 8)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'enf_hora_inicio'))
                $table->string('enf_hora_inicio', 8)->nullable();
            if (!Schema::connection('compras')->hasColumn('brew_lote_coccion', 'enf_hora_fin'))
                $table->string('enf_hora_fin', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_coccion', function (Blueprint $table) {
            $table->dropColumn([
                'macerado_hora_inicio', 'macerado_hora_fin',
                'hervor_hora_inicio', 'hervor_hora_fin',
                'enf_hora_inicio', 'enf_hora_fin',
            ]);
        });
    }
};
