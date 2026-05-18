<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql';

    public function up(): void
    {
        if (!DB::connection('pgsql')->table('roles')->where('codigo', 'dir_comercial')->where('system_id', 2)->exists()) {
            DB::connection('pgsql')->table('roles')->insert([
                'nombre'      => 'Dirección Comercial',
                'codigo'      => 'dir_comercial',
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
        DB::connection('pgsql')->table('roles')
            ->where('codigo', 'dir_comercial')->where('system_id', 2)->delete();
    }
};
