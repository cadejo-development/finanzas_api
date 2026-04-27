<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        // Sistema compras = ID 2
        $existe = DB::connection('pgsql')
            ->table('roles')
            ->where('codigo', 'cocinero')
            ->where('system_id', 2)
            ->exists();

        if (!$existe) {
            DB::connection('pgsql')->table('roles')->insert([
                'nombre'      => 'Cocinero',
                'codigo'      => 'cocinero',
                'system_id'   => 2,
                'aud_usuario' => 'sistema',
                'is_active'   => true,
                'created_at'  => now(),
                'updated_at'  => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::connection('pgsql')
            ->table('roles')
            ->where('codigo', 'cocinero')
            ->where('system_id', 2)
            ->delete();
    }
};
