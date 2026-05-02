<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        DB::connection('pgsql')->table('sucursales')->insertOrIgnore([
            'codigo'      => 'SUC-ML',
            'nombre'      => 'RESTAURANTE MALCRIADAS',
            'tipo'        => 'operativa',
            'activa'      => true,
            'aud_usuario' => 'migration',
            'created_at'  => now(),
            'updated_at'  => now(),
        ]);
    }

    public function down(): void
    {
        DB::connection('pgsql')->table('sucursales')->where('codigo', 'SUC-ML')->delete();
    }
};
