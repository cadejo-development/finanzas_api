<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('merma_presentaciones', function (Blueprint $table) {
            $table->id();
            $table->string('presentacion', 50);
            $table->decimal('oz_nominal',   8, 3);
            $table->decimal('oz_efectivas', 8, 3);
            $table->boolean('activa')->default(true);
            $table->unsignedSmallInteger('orden')->default(0);
        });

        // Presentaciones del mockup — fuente: levantamiento Brilo
        $rows = [
            ['4 Oz',        4,      3.79,  1],
            ['12 Oz',      12,      9.40,  2],
            ['14 Oz',      14,     10.40,  3],
            ['16 Oz',      16,     13.86,  4],
            ['23 Oz',      23,     19.27,  5],
            ['32 Oz',      32,     30.00,  6],
            ['1 Lt',       33.814, 31.69,  7],
            ['1.25 Lts',   42.268, 40.14,  8],
            ['2 Lts',      67.628, 63.38,  9],
            ['3 Lts',     101.442, 95.06, 10],
            ['Sampler 6',  24,     22.74, 11],
            ['Sampler 4',  56,     41.60, 12],
            ['Sampler 3',  12,     15.16, 13],
            ['Refill 1 Lt', 33.814, 31.69, 14],
            ['Refill 2 Lts', 67.628, 63.38, 15],
        ];

        foreach ($rows as [$pres, $nom, $ef, $orden]) {
            DB::connection('compras')->table('merma_presentaciones')->insert([
                'presentacion' => $pres,
                'oz_nominal'   => $nom,
                'oz_efectivas' => $ef,
                'activa'       => true,
                'orden'        => $orden,
            ]);
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_presentaciones');
    }
};
