<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'pgsql_ops';

    public function up(): void
    {
        $cervezas = [
            ['nombre' => 'Cerveza Calabaza',    'estilo' => 'Ale de Temporada', 'color_hex' => '#D4580A', 'orden' => 10],
            ['nombre' => 'Cerveza Lupe Reyes',  'estilo' => null,               'color_hex' => '#C8A422', 'orden' => 11],
            ['nombre' => 'Cerveza Oktoberfest', 'estilo' => 'Marzen',           'color_hex' => '#B85C1E', 'orden' => 12],
        ];

        foreach ($cervezas as $c) {
            $existe = DB::connection($this->connection)
                ->table('merma_cervezas')
                ->where('nombre', $c['nombre'])
                ->exists();

            if (!$existe) {
                DB::connection($this->connection)->table('merma_cervezas')->insert([
                    'nombre'         => $c['nombre'],
                    'estilo'         => $c['estilo'],
                    'color_hex'      => $c['color_hex'],
                    'estado'         => 'activo',
                    'vigencia_desde' => '2026-10-14',
                    'orden'          => $c['orden'],
                    'created_at'     => now(),
                    'updated_at'     => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        DB::connection($this->connection)
            ->table('merma_cervezas')
            ->whereIn('nombre', ['Cerveza Calabaza', 'Cerveza Lupe Reyes', 'Cerveza Oktoberfest'])
            ->delete();
    }
};
