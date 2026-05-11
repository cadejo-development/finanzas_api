<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): string { return 'rrhh'; }

    public function up(): void
    {
        $existe = DB::connection('rrhh')
            ->table('tipos_falta')
            ->where('codigo', 'FALTA_MUY_GRAVE')
            ->exists();

        if (!$existe) {
            DB::connection('rrhh')->table('tipos_falta')->insert([
                'codigo'      => 'FALTA_MUY_GRAVE',
                'nombre'      => 'Falta Muy Grave',
                'gravedad'    => 'muy_grave',
                'activo'      => true,
                'aud_usuario' => 'sistema:migration',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('rrhh')
            ->table('tipos_falta')
            ->where('codigo', 'FALTA_MUY_GRAVE')
            ->delete();
    }
};
