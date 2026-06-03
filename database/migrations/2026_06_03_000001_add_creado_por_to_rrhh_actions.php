<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'rrhh';

    public function up(): void
    {
        $tables = [
            'permisos',
            'vacaciones',
            'incapacidades',
            'amonestaciones',
            'ausencias_injustificadas',
            'desvinculaciones',
            'traslados',
            'cambios_salariales',
        ];

        foreach ($tables as $table) {
            Schema::connection('rrhh')->table($table, function (Blueprint $t) {
                $t->string('creado_por', 80)->nullable()->after('aud_usuario');
            });
        }
    }

    public function down(): void
    {
        $tables = [
            'permisos', 'vacaciones', 'incapacidades', 'amonestaciones',
            'ausencias_injustificadas', 'desvinculaciones', 'traslados', 'cambios_salariales',
        ];

        foreach ($tables as $table) {
            Schema::connection('rrhh')->table($table, function (Blueprint $t) {
                $t->dropColumn('creado_por');
            });
        }
    }
};
