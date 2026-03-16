<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    // Esta migración corre contra la DB core (pgsql)
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        // Solo insertar si no existe ya
        $exists = DB::connection('pgsql')
            ->table('systems')
            ->where('codigo', 'rrhh')
            ->exists();

        if (!$exists) {
            DB::connection('pgsql')->table('systems')->insert([
                'nombre'      => 'Recursos Humanos',
                'codigo'      => 'rrhh',
                'url'         => env('RRHH_APP_URL', 'http://localhost:5174'),
                'color'       => '#f59e0b',
                'icon'        => 'fa-users',
                'descripcion' => 'Gestión de acciones de personal: permisos, vacaciones, incapacidades, amonestaciones y más.',
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('pgsql')
            ->table('systems')
            ->where('codigo', 'rrhh')
            ->delete();
    }
};
