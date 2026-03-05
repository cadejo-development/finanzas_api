<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        if (!Schema::connection('compras')->hasTable('pedidos')) {
            Schema::connection('compras')->create('pedidos', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('sucursal_id');          // ref a sucursales en pgsql (sin FK cross-db)
                $table->unsignedBigInteger('centro_costo_id')->nullable();
                $table->date('semana_inicio');
                $table->date('semana_fin')->nullable();
                $table->string('estado', 20)->default('BORRADOR'); // BORRADOR | ENVIADO
                $table->decimal('total_estimado', 12, 2)->nullable();
                $table->string('aud_usuario', 150)->nullable();
                $table->timestamps();

                $table->unique(['sucursal_id', 'semana_inicio']);
                $table->index(['estado', 'semana_inicio']);
            });
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('pedidos');
    }
};
