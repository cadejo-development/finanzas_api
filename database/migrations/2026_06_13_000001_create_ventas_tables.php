<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        Schema::connection('compras')->create('ventas_clientes', function (Blueprint $table) {
            $table->id();
            $table->integer('brilo_id')->unique()->nullable();
            $table->string('nombres');
            $table->string('nom_comercial')->nullable();
            $table->string('nit')->nullable();
            $table->string('registro_iva')->nullable();
            $table->string('email')->nullable();
            $table->string('telefono')->nullable();
            $table->string('direccion')->nullable();
            $table->boolean('exento')->default(false);
            $table->integer('plazo_credito')->default(0);
            $table->decimal('limite_credito', 12, 2)->default(0);
            $table->tinyInteger('brilo_status')->default(0);
            $table->integer('brilo_vendedor_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::connection('compras')->create('ventas_productos', function (Blueprint $table) {
            $table->id();
            $table->integer('brilo_id')->unique()->nullable();
            $table->string('codigo')->nullable();
            $table->string('nombre');
            $table->boolean('exento')->default(true);
            $table->decimal('precio', 12, 4)->nullable();
            $table->decimal('existencias', 12, 2)->default(0);
            $table->string('bodega')->nullable();
            $table->integer('bodega_id')->nullable();
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        Schema::connection('compras')->create('ventas_ordenes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cliente_id')->constrained('ventas_clientes');
            $table->string('tipo_venta'); // contado, credito
            $table->integer('plazo_solicitado')->default(0);
            $table->string('estado')->default('borrador'); // borrador, pendiente_aprobacion, aprobada, rechazada, completada
            $table->decimal('subtotal', 12, 2)->default(0);
            $table->decimal('total_iva', 12, 2)->default(0);
            $table->decimal('total', 12, 2)->default(0);
            $table->text('notas')->nullable();
            $table->string('creado_por')->nullable();
            $table->string('aprobado_por')->nullable();
            $table->timestamp('aprobado_at')->nullable();
            $table->timestamps();
        });

        Schema::connection('compras')->create('ventas_orden_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('orden_id')->constrained('ventas_ordenes')->cascadeOnDelete();
            $table->foreignId('producto_id')->constrained('ventas_productos');
            $table->string('nombre_producto');
            $table->decimal('cantidad', 12, 2);
            $table->decimal('precio_unitario', 12, 4);
            $table->boolean('exento')->default(true);
            $table->decimal('subtotal', 12, 2);
            $table->decimal('iva', 12, 2)->default(0);
            $table->decimal('total', 12, 2);
            $table->timestamps();
        });

        Schema::connection('compras')->create('ventas_aprobaciones', function (Blueprint $table) {
            $table->id();
            $table->string('tipo'); // cambio_credito, exceso_limite, modificacion_orden
            $table->foreignId('orden_id')->nullable()->constrained('ventas_ordenes')->nullOnDelete();
            $table->foreignId('cliente_id')->nullable()->constrained('ventas_clientes')->nullOnDelete();
            $table->text('detalle')->nullable();
            $table->string('estado')->default('pendiente'); // pendiente, aprobado, rechazado
            $table->string('solicitado_por')->nullable();
            $table->string('resuelto_por')->nullable();
            $table->text('nota_resolucion')->nullable();
            $table->timestamp('resuelto_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('ventas_aprobaciones');
        Schema::connection('compras')->dropIfExists('ventas_orden_items');
        Schema::connection('compras')->dropIfExists('ventas_ordenes');
        Schema::connection('compras')->dropIfExists('ventas_productos');
        Schema::connection('compras')->dropIfExists('ventas_clientes');
    }
};
