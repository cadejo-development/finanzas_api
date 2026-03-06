<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Tabla catálogo de etiquetas / línea de gasto
        if (!Schema::connection('pagos')->hasTable('etiquetas')) {
            Schema::connection('pagos')->create('etiquetas', function (Blueprint $table) {
                $table->id();
                $table->string('codigo', 5)->unique();
                $table->string('nombre', 100);
                $table->timestamps();
            });
        }

        // Sembrar las 35 etiquetas
        $etiquetas = [
            ['codigo' => '01', 'nombre' => 'ALQUILERES'],
            ['codigo' => '02', 'nombre' => 'ATENCION A EMPLEADOS'],
            ['codigo' => '03', 'nombre' => 'BEBIDAS RESTAURANTE'],
            ['codigo' => '04', 'nombre' => 'CAJA CHICA'],
            ['codigo' => '05', 'nombre' => 'CAPEX'],
            ['codigo' => '06', 'nombre' => 'COMBUSTIBLE'],
            ['codigo' => '07', 'nombre' => 'COMISIONES COMERCIALES'],
            ['codigo' => '08', 'nombre' => 'DEPOSITOS'],
            ['codigo' => '09', 'nombre' => 'DIVIDENDOS'],
            ['codigo' => '10', 'nombre' => 'ENTES REGULATORIOS'],
            ['codigo' => '11', 'nombre' => 'EVENTOS SUCURSALES'],
            ['codigo' => '12', 'nombre' => 'GASTOS GENERALES'],
            ['codigo' => '13', 'nombre' => 'HONORARIOS'],
            ['codigo' => '14', 'nombre' => 'IMPUESTOS'],
            ['codigo' => '15', 'nombre' => 'INSUMOS DE LIMPIEZA'],
            ['codigo' => '16', 'nombre' => 'INSUMOS OFICINA'],
            ['codigo' => '17', 'nombre' => 'INSUMOS RESTAURANTE'],
            ['codigo' => '18', 'nombre' => 'INTANGIBLES'],
            ['codigo' => '19', 'nombre' => 'INTERCOMPANY'],
            ['codigo' => '20', 'nombre' => 'INVENTARIO PROMOCIONALES'],
            ['codigo' => '21', 'nombre' => 'INVENTARIO QUIMICOS'],
            ['codigo' => '22', 'nombre' => 'MANTENIMIENTO'],
            ['codigo' => '23', 'nombre' => 'MOBILIARIO Y EQUIPO'],
            ['codigo' => '24', 'nombre' => 'MP PRODUCCION'],
            ['codigo' => '25', 'nombre' => 'MP RESTAURANTE'],
            ['codigo' => '26', 'nombre' => 'OTROS CREDITOS'],
            ['codigo' => '27', 'nombre' => 'PREVISIONES LABORALES'],
            ['codigo' => '28', 'nombre' => 'PUBLICIDAD'],
            ['codigo' => '29', 'nombre' => 'REINTEGRO RESERVA'],
            ['codigo' => '30', 'nombre' => 'REMUNERACIONES PERSONAL'],
            ['codigo' => '31', 'nombre' => 'SEGUROS'],
            ['codigo' => '32', 'nombre' => 'SERVICIOS BASICOS'],
            ['codigo' => '33', 'nombre' => 'SERVICIOS FINANCIEROS'],
            ['codigo' => '34', 'nombre' => 'SUMINISTROS RESTAURANTE'],
            ['codigo' => '35', 'nombre' => 'VIATICOS PERSONAL'],
        ];

        foreach ($etiquetas as $e) {
            DB::connection('pagos')->table('etiquetas')->updateOrInsert(
                ['codigo' => $e['codigo']],
                ['nombre' => $e['nombre'], 'updated_at' => now(), 'created_at' => now()]
            );
        }

        // Agregar columna etiqueta_codigo a solicitud_pago_detalles
        DB::connection('pagos')->statement('
            ALTER TABLE solicitud_pago_detalles
            ADD COLUMN IF NOT EXISTS etiqueta_codigo VARCHAR(5) NULL
        ');
    }

    public function down(): void
    {
        DB::connection('pagos')->statement('
            ALTER TABLE solicitud_pago_detalles
            DROP COLUMN IF EXISTS etiqueta_codigo
        ');
        Schema::connection('pagos')->dropIfExists('etiquetas');
    }
};
