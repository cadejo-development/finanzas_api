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
        Schema::connection('compras')->create('merma_config', function (Blueprint $table) {
            $table->id();
            $table->decimal('densidad_kg_l',   6, 4)->default(1.0100);
            $table->decimal('tara_pequeno_lb',  6, 2)->default(14.00);
            $table->decimal('tara_grande_lb',   6, 2)->default(28.00);
            $table->decimal('barril_pequeno_oz',10, 2)->default(661.00);
            $table->decimal('barril_grande_oz', 10, 2)->default(1986.26);
            $table->decimal('semaforo_normal',   5, 2)->default(12.00);
            $table->decimal('semaforo_revisar',  5, 2)->default(18.00);
            $table->decimal('semaforo_alerta',   5, 2)->default(25.00);
            $table->decimal('meta_pct',          5, 2)->default(10.00);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        // Insertar el registro global único
        DB::connection('compras')->table('merma_config')->insert([
            'densidad_kg_l'   => 1.0100,
            'tara_pequeno_lb' => 14.00,
            'tara_grande_lb'  => 28.00,
            'barril_pequeno_oz' => 661.00,
            'barril_grande_oz'  => 1986.26,
            'semaforo_normal'   => 12.00,
            'semaforo_revisar'  => 18.00,
            'semaforo_alerta'   => 25.00,
            'meta_pct'          => 10.00,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('merma_config');
    }
};
