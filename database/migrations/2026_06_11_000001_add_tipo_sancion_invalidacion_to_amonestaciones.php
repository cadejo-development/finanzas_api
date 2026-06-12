<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            // Tipo de sanción formal (verbal, escrita, suspensión)
            $table->string('tipo_sancion')->nullable()->after('accion_tomada');

            // Campos de invalidación — las amonestaciones no se eliminan, se invalidan
            $table->boolean('invalidada')->default(false)->after('estado');
            $table->unsignedBigInteger('invalidada_por')->nullable()->after('invalidada');
            $table->timestamp('invalidada_en')->nullable()->after('invalidada_por');
            $table->text('motivo_invalidacion')->nullable()->after('invalidada_en');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            $table->dropColumn([
                'tipo_sancion',
                'invalidada',
                'invalidada_por',
                'invalidada_en',
                'motivo_invalidacion',
            ]);
        });
    }
};
