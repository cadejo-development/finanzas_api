<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        $sistema = DB::connection('pgsql')
            ->table('systems')
            ->where('codigo', 'rrhh')
            ->first();

        if (!$sistema) {
            return;
        }

        $existe = DB::connection('pgsql')
            ->table('roles')
            ->where('codigo', 'empleado')
            ->where('system_id', $sistema->id)
            ->exists();

        if (!$existe) {
            DB::connection('pgsql')->table('roles')->insert([
                'nombre'     => 'Empleado',
                'codigo'     => 'empleado',
                'system_id'  => $sistema->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $sistema = DB::connection('pgsql')
            ->table('systems')
            ->where('codigo', 'rrhh')
            ->first();

        if ($sistema) {
            DB::connection('pgsql')
                ->table('roles')
                ->where('codigo', 'empleado')
                ->where('system_id', $sistema->id)
                ->delete();
        }
    }
};
