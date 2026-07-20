<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->table('brew_lote_ferm_seguimiento', function (Blueprint $table) {
            $table->decimal('gravedad_obj', 6, 4)->nullable()->after('notas')
                ->comment('Gravedad objetivo del día (SG)');
            $table->decimal('temp_obj', 5, 1)->nullable()->after('gravedad_obj')
                ->comment('Temperatura objetivo del día °C');
            $table->decimal('ph_obj', 4, 2)->nullable()->after('temp_obj')
                ->comment('pH objetivo del día');
            $table->text('accion_ajuste')->nullable()->after('ph_obj')
                ->comment('Acción/ajuste realizado ese día');
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->table('brew_lote_ferm_seguimiento', function (Blueprint $table) {
            $table->dropColumn(['gravedad_obj', 'temp_obj', 'ph_obj', 'accion_ajuste']);
        });
    }
};
