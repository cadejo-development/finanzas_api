<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Mapeo: código antiguo → código nuevo
        $mapeo = [
            'MATERNIDAD'  => 'maternidad',
            'PATERNIDAD'  => 'paternidad',
            'MATRIMONIO'  => 'matrimonio',
            'FALLECIMIENTO' => 'fallecimiento_familiar',
        ];

        $conn = DB::connection('rrhh');

        foreach ($mapeo as $viejo => $nuevo) {
            $idViejo = $conn->table('tipos_permiso')->where('codigo', $viejo)->value('id');
            $idNuevo = $conn->table('tipos_permiso')->where('codigo', $nuevo)->value('id');

            if ($idViejo && $idNuevo) {
                $conn->table('permisos')
                    ->where('tipo_permiso_id', $idViejo)
                    ->update(['tipo_permiso_id' => $idNuevo]);
            }
        }
    }

    public function down(): void
    {
        // No reversible: no tiene sentido volver a los tipos desactivados
    }
};
