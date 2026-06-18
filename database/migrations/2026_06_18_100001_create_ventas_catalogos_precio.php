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
        Schema::connection('compras')->create('ventas_catalogos_precio', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('descripcion')->nullable();
            $table->boolean('activo')->default(true);
            $table->integer('dias_alerta_revision')->default(90);
            $table->timestamps();
        });

        Schema::connection('compras')->create('ventas_catalogo_precio_lineas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('catalogo_id')->constrained('ventas_catalogos_precio')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('ventas_productos')->cascadeOnDelete();
            $table->decimal('precio_sin_iva', 12, 4);
            $table->decimal('precio_con_iva', 12, 4);
            $table->timestamps();
            $table->unique(['catalogo_id', 'producto_id']);
        });

        // Seed catálogos del Excel
        $ahora = now();
        DB::connection('compras')->table('ventas_catalogos_precio')->insert([
            ['nombre' => 'Hotel Quality',    'descripcion' => 'Barril — Hotel Quality',     'activo' => true, 'dias_alerta_revision' => 90, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Barril',           'descripcion' => 'Barril estándar',             'activo' => true, 'dias_alerta_revision' => 90, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Temporada Barril', 'descripcion' => 'Tarifa barril de temporada',  'activo' => true, 'dias_alerta_revision' => 90, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Republik',         'descripcion' => 'Botella — Republik',          'activo' => true, 'dias_alerta_revision' => 90, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Pedidos Ya',       'descripcion' => 'Botella — Pedidos Ya',        'activo' => true, 'dias_alerta_revision' => 90, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Estándar',         'descripcion' => 'Tarifa estándar',             'activo' => true, 'dias_alerta_revision' => 90, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Temporada Bot',    'descripcion' => 'Botella de temporada',        'activo' => true, 'dias_alerta_revision' => 90, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Agua Dura',        'descripcion' => 'Botella — Agua Dura',         'activo' => true, 'dias_alerta_revision' => 90, 'created_at' => $ahora, 'updated_at' => $ahora],
            ['nombre' => 'Agua Fresca',      'descripcion' => 'Botella — Agua Fresca',       'activo' => true, 'dias_alerta_revision' => 90, 'created_at' => $ahora, 'updated_at' => $ahora],
        ]);
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('ventas_catalogo_precio_lineas');
        Schema::connection('compras')->dropIfExists('ventas_catalogos_precio');
    }
};
