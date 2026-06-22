<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'compras';

    public function up(): void
    {
        // ── Columnas adicionales en ventas_ordenes ────────────────────────────
        Schema::connection('compras')->table('ventas_ordenes', function (Blueprint $table) {
            if (!Schema::connection('compras')->hasColumn('ventas_ordenes', 'tipo_documento')) {
                $table->string('tipo_documento')->default('ccf')->after('tipo_venta');
            }
            if (!Schema::connection('compras')->hasColumn('ventas_ordenes', 'tipo_orden')) {
                $table->string('tipo_orden')->default('normal')->nullable()->after('tipo_documento');
            }
            if (!Schema::connection('compras')->hasColumn('ventas_ordenes', 'sub_ceco')) {
                $table->string('sub_ceco')->nullable()->after('tipo_orden');
            }
            if (!Schema::connection('compras')->hasColumn('ventas_ordenes', 'despachada_at')) {
                $table->timestamp('despachada_at')->nullable()->after('aprobado_at');
            }
            if (!Schema::connection('compras')->hasColumn('ventas_ordenes', 'despachada_por')) {
                $table->string('despachada_por')->nullable()->after('despachada_at');
            }
            if (!Schema::connection('compras')->hasColumn('ventas_ordenes', 'facturado')) {
                $table->boolean('facturado')->default(false)->after('despachada_por');
            }
            if (!Schema::connection('compras')->hasColumn('ventas_ordenes', 'facturado_por')) {
                $table->string('facturado_por')->nullable()->after('facturado');
            }
            if (!Schema::connection('compras')->hasColumn('ventas_ordenes', 'facturado_at')) {
                $table->timestamp('facturado_at')->nullable()->after('facturado_por');
            }
        });

        // ── Registro de pagos ─────────────────────────────────────────────────
        if (!Schema::connection('compras')->hasTable('ventas_pagos')) {
            Schema::connection('compras')->create('ventas_pagos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('orden_id')->constrained('ventas_ordenes')->cascadeOnDelete();
                $table->date('fecha');
                $table->string('forma_pago'); // efectivo, transferencia, cheque, tarjeta, otro
                $table->decimal('monto', 12, 2);
                $table->string('comprobante')->nullable();
                $table->string('registrado_por')->nullable();
                $table->text('notas')->nullable();
                $table->timestamps();
            });
        }

        // ── Documentos de cliente ─────────────────────────────────────────────
        if (!Schema::connection('compras')->hasTable('ventas_cliente_documentos')) {
            Schema::connection('compras')->create('ventas_cliente_documentos', function (Blueprint $table) {
                $table->id();
                $table->foreignId('cliente_id')->constrained('ventas_clientes')->cascadeOnDelete();
                $table->string('tipo'); // tarjeta_iva, carta_exencion, otro
                $table->string('nombre_archivo');
                $table->string('ruta_archivo');
                $table->string('subido_por')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection('compras')->dropIfExists('ventas_cliente_documentos');
        Schema::connection('compras')->dropIfExists('ventas_pagos');
        Schema::connection('compras')->table('ventas_ordenes', function (Blueprint $table) {
            $cols = ['tipo_documento', 'despachada_at', 'despachada_por', 'facturado', 'facturado_por', 'facturado_at'];
            foreach ($cols as $col) {
                if (Schema::connection('compras')->hasColumn('ventas_ordenes', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
