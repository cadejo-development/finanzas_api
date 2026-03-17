<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'pagos';

    public function up(): void
    {
        Schema::connection('pagos')->create('reglas_aprobacion', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_gasto', 10);           // OPEX | CAPEX
            $table->unsignedTinyInteger('nivel_orden');  // orden de la etapa (0, 1, ...)
            $table->string('nivel_codigo', 50);          // visto_bueno | nivel_1 | nivel_2 ...
            $table->string('rol_requerido', 60);         // codigo del rol
            $table->string('etiqueta', 100);             // nombre para mostrar en frontend
            $table->decimal('monto_min', 15, 2)->nullable(); // null = desde $0
            $table->decimal('monto_max', 15, 2)->nullable(); // null = sin límite
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });

        // ── Seed inicial ────────────────────────────────────────────────────────
        $now = now();

        // OPEX — sin visto bueno, un solo nivel según monto
        DB::connection('pagos')->table('reglas_aprobacion')->insert([
            [
                'tipo_gasto'    => 'OPEX',
                'nivel_orden'   => 0,
                'nivel_codigo'  => 'nivel_1',
                'rol_requerido' => 'gerente_sucursal',
                'etiqueta'      => 'Gerente Sucursal',
                'monto_min'     => 0,
                'monto_max'     => 149.99,
                'activo'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'tipo_gasto'    => 'OPEX',
                'nivel_orden'   => 0,
                'nivel_codigo'  => 'nivel_2',
                'rol_requerido' => 'gerencia_area',
                'etiqueta'      => 'Gerencia de Área',
                'monto_min'     => 150.00,
                'monto_max'     => 499.99,
                'activo'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'tipo_gasto'    => 'OPEX',
                'nivel_orden'   => 0,
                'nivel_codigo'  => 'nivel_3',
                'rol_requerido' => 'gerencia_financiera',
                'etiqueta'      => 'Gerencia Financiera',
                'monto_min'     => 500.00,
                'monto_max'     => 1999.99,
                'activo'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'tipo_gasto'    => 'OPEX',
                'nivel_orden'   => 0,
                'nivel_codigo'  => 'nivel_4',
                'rol_requerido' => 'gerencia_general',
                'etiqueta'      => 'Gerencia General',
                'monto_min'     => 2000.00,
                'monto_max'     => null,
                'activo'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            // CAPEX — visto bueno paralelo (sin rango = siempre aplica), luego un nivel según monto
            [
                'tipo_gasto'    => 'CAPEX',
                'nivel_orden'   => 0,
                'nivel_codigo'  => 'visto_bueno',
                'rol_requerido' => 'gerente_logistica',
                'etiqueta'      => 'Gerente Logística',
                'monto_min'     => null,
                'monto_max'     => null,
                'activo'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'tipo_gasto'    => 'CAPEX',
                'nivel_orden'   => 0,
                'nivel_codigo'  => 'visto_bueno',
                'rol_requerido' => 'gerente_mantenimiento',
                'etiqueta'      => 'Gerente Mantenimiento',
                'monto_min'     => null,
                'monto_max'     => null,
                'activo'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'tipo_gasto'    => 'CAPEX',
                'nivel_orden'   => 1,
                'nivel_codigo'  => 'nivel_1',
                'rol_requerido' => 'gerencia_area',
                'etiqueta'      => 'Gerencia de Área',
                'monto_min'     => 0,
                'monto_max'     => 499.99,
                'activo'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'tipo_gasto'    => 'CAPEX',
                'nivel_orden'   => 1,
                'nivel_codigo'  => 'nivel_2',
                'rol_requerido' => 'gerencia_financiera',
                'etiqueta'      => 'Gerencia Financiera',
                'monto_min'     => 500.00,
                'monto_max'     => 1499.99,
                'activo'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'tipo_gasto'    => 'CAPEX',
                'nivel_orden'   => 1,
                'nivel_codigo'  => 'nivel_3',
                'rol_requerido' => 'gerencia_general',
                'etiqueta'      => 'Gerencia General',
                'monto_min'     => 1500.00,
                'monto_max'     => null,
                'activo'        => true,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ]);
    }

    public function down(): void
    {
        Schema::connection('pagos')->dropIfExists('reglas_aprobacion');
    }
};
