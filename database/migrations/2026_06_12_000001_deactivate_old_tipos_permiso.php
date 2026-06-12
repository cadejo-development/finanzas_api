<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Desactivar tipos de permisos especiales duplicados (versiones antiguas)
        // Los nuevos equivalentes son: maternidad, paternidad, matrimonio, fallecimiento_familiar
        DB::connection('rrhh')->table('tipos_permiso')
            ->whereIn('codigo', ['MATERNIDAD', 'PATERNIDAD', 'MATRIMONIO', 'FALLECIMIENTO'])
            ->update(['activo' => false]);
    }

    public function down(): void
    {
        DB::connection('rrhh')->table('tipos_permiso')
            ->whereIn('codigo', ['MATERNIDAD', 'PATERNIDAD', 'MATRIMONIO', 'FALLECIMIENTO'])
            ->update(['activo' => true]);
    }
};
