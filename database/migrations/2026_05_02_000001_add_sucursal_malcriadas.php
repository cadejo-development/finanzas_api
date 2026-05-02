<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function getConnection(): string { return 'pgsql'; }

    public function up(): void
    {
        $schema = \Illuminate\Support\Facades\Schema::connection('pgsql');

        $row = [
            'codigo'      => 'SUC-ML',
            'nombre'      => 'RESTAURANTE MALCRIADAS',
            'aud_usuario' => 'migration',
            'created_at'  => now(),
            'updated_at'  => now(),
        ];

        if ($schema->hasColumn('sucursales', 'tipo'))             $row['tipo']             = 'operativa';
        if ($schema->hasColumn('sucursales', 'activa'))           $row['activa']           = true;
        if ($schema->hasColumn('sucursales', 'tipo_sucursal_id')) $row['tipo_sucursal_id'] = 1;

        DB::connection('pgsql')->table('sucursales')->insertOrIgnore($row);
    }

    public function down(): void
    {
        DB::connection('pgsql')->table('sucursales')->where('codigo', 'SUC-ML')->delete();
    }
};
