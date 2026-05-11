<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        // Amonestaciones: estado de aprobación (solo aplica cuando hay suspensión de propina)
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            $table->string('estado', 40)->default('aprobado')->after('aplica_suspension_propina');
            $table->integer('aprobado_por_id')->nullable()->after('estado');
            $table->timestamp('aprobado_en')->nullable()->after('aprobado_por_id');
            $table->text('rechazo_motivo')->nullable()->after('aprobado_en');
        });

        // Desvinculaciones: estado de aprobación (solo aplica a despidos de restaurantes)
        Schema::connection('rrhh')->table('desvinculaciones', function (Blueprint $table) {
            $table->string('estado', 40)->default('aprobado')->after('observaciones');
            $table->integer('aprobado_por_id')->nullable()->after('estado');
            $table->timestamp('aprobado_en')->nullable()->after('aprobado_por_id');
            $table->text('rechazo_motivo')->nullable()->after('aprobado_en');
        });
    }

    public function down(): void
    {
        Schema::connection('rrhh')->table('amonestaciones', function (Blueprint $table) {
            $table->dropColumn(['estado', 'aprobado_por_id', 'aprobado_en', 'rechazo_motivo']);
        });

        Schema::connection('rrhh')->table('desvinculaciones', function (Blueprint $table) {
            $table->dropColumn(['estado', 'aprobado_por_id', 'aprobado_en', 'rechazo_motivo']);
        });
    }
};
