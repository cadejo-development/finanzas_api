<?php

namespace App\Services\RRHH;

use Illuminate\Support\Facades\DB;

/**
 * Servicio de cálculo de planillas quincenales.
 * Aplica las tasas laborales de El Salvador (2024/2025):
 *   AFP empleado  7.25%   (tope quincenal configurable)
 *   AFP patronal  8.75%   (mismo tope)
 *   ISSS empleado 3.0%    (tope quincenal configurable, max $15)
 *   ISSS patronal 7.5%    (mismo tope)
 *   INSAFORP      1.0%    (sin tope, solo patronal)
 *   Renta (ISR)   tabla quincenal vigente en DB
 */
class PlanillaCalculatorService
{
    private const ISSS_MAX_EMP = 15.00; // máximo descuento ISSS empleado por quincena

    // Caché estático a nivel de request (sin depender del driver de caché de Laravel)
    private static array $configCache = [];
    private static array $rentaCache  = [];

    // ─── Configuración ───────────────────────────────────────────────────────

    /**
     * Retorna el mapa clave→valor de planilla_config (cacheado por request).
     */
    public function getConfig(): array
    {
        if (empty(self::$configCache)) {
            $rows = DB::connection('pgsql')
                ->table('planilla_config')
                ->get(['clave', 'valor']);

            foreach ($rows as $row) {
                self::$configCache[$row->clave] = (float) $row->valor;
            }
        }
        return self::$configCache;
    }

    /**
     * Retorna los tramos de renta activos, ordenados por desde (cacheado por request).
     */
    public function getTablaRenta(): array
    {
        if (empty(self::$rentaCache)) {
            self::$rentaCache = DB::connection('pgsql')
                ->table('planilla_tabla_renta')
                ->where('activo', true)
                ->orderBy('desde')
                ->get()
                ->map(fn($r) => (array) $r)
                ->all();
        }
        return self::$rentaCache;
    }

    /**
     * Limpia la caché estática (llamar tras guardar cambios en mantenimiento).
     */
    public function clearCache(): void
    {
        self::$configCache = [];
        self::$rentaCache  = [];
    }

    // ─── Cálculos por empleado ───────────────────────────────────────────────

    /**
     * Salario proporcional según días laborados.
     */
    public function calcularSalarioProporcional(
        float $salario,
        float $diasLaborados,
        int   $diasQuincena
    ): float {
        if ($diasQuincena <= 0) return 0.0;
        return round($salario * ($diasLaborados / $diasQuincena), 2);
    }

    /**
     * AFP descuento empleado.
     */
    public function calcularAFPEmpleado(float $salario): float
    {
        $cfg  = $this->getConfig();
        $tope = $cfg['afp_tope_quincenal'] ?? 3814.59;
        $pct  = ($cfg['afp_empleado_porcentaje'] ?? 7.25) / 100;
        return round(min($salario, $tope) * $pct, 2);
    }

    /**
     * AFP aporte patronal.
     */
    public function calcularAFPPatronal(float $salario): float
    {
        $cfg  = $this->getConfig();
        $tope = $cfg['afp_tope_quincenal'] ?? 3814.59;
        $pct  = ($cfg['afp_patronal_porcentaje'] ?? 8.75) / 100;
        return round(min($salario, $tope) * $pct, 2);
    }

    /**
     * ISSS descuento empleado (máx $15 por quincena).
     */
    public function calcularISSSEmpleado(float $salario): float
    {
        $cfg  = $this->getConfig();
        $tope = $cfg['isss_tope_quincenal'] ?? 500.00;
        $pct  = ($cfg['isss_empleado_porcentaje'] ?? 3.00) / 100;
        $calc = round(min($salario, $tope) * $pct, 2);
        return round(min($calc, self::ISSS_MAX_EMP), 2);
    }

    /**
     * ISSS aporte patronal.
     */
    public function calcularISSSPatronal(float $salario): float
    {
        $cfg  = $this->getConfig();
        $tope = $cfg['isss_tope_quincenal'] ?? 500.00;
        $pct  = ($cfg['isss_patronal_porcentaje'] ?? 7.50) / 100;
        return round(min($salario, $tope) * $pct, 2);
    }

    /**
     * INSAFORP aporte patronal (1%, sin tope).
     */
    public function calcularINSAFORP(float $salario): float
    {
        $cfg = $this->getConfig();
        $pct = ($cfg['insaforp_patronal_porcentaje'] ?? 1.00) / 100;
        return round($salario * $pct, 2);
    }

    /**
     * Renta (ISR quincenal) sobre base gravable.
     * base_gravable = salario_proporcional - afp_empleado  (Art. 33 Ley ISR)
     */
    public function calcularRenta(float $baseGravable): float
    {
        if ($baseGravable <= 0) return 0.0;

        $tabla = $this->getTablaRenta();

        foreach ($tabla as $tramo) {
            $desde = (float) $tramo['desde'];
            $hasta = isset($tramo['hasta']) && $tramo['hasta'] !== null
                ? (float) $tramo['hasta']
                : PHP_FLOAT_MAX;

            if ($baseGravable >= $desde && $baseGravable <= $hasta) {
                $cuotaFija    = (float) $tramo['cuota_fija'];
                $porcentaje   = (float) $tramo['porcentaje'] / 100;
                $sobreExceso  = (float) $tramo['sobre_exceso_de'];

                $renta = $cuotaFija + ($baseGravable - $sobreExceso) * $porcentaje;
                return round(max(0, $renta), 2);
            }
        }

        return 0.0;
    }

    /**
     * Cálculo completo de un empleado para la quincena.
     *
     * @param float $salarioBase       Salario base quincenal
     * @param float $diasLaborados     Días realmente trabajados
     * @param int   $diasQuincena      Días totales de la quincena (14, 15 o 16)
     * @param array $ordenes           Órdenes de descuento activas [{concepto, monto_quincenal}]
     * @return array
     */
    public function calcularPlanillaEmpleado(
        float $salarioBase,
        float $diasLaborados,
        int   $diasQuincena,
        array $ordenes = []
    ): array {
        // Proporcional
        $salarioProp = $this->calcularSalarioProporcional($salarioBase, $diasLaborados, $diasQuincena);

        // Deducciones empleado
        $afpEmp  = $this->calcularAFPEmpleado($salarioProp);
        $isssEmp = $this->calcularISSSEmpleado($salarioProp);

        // Base para renta = salario prop - AFP (AFP es deducible del ISR)
        $baseRenta = round($salarioProp - $afpEmp, 2);
        $renta     = $this->calcularRenta($baseRenta);

        // Órdenes de descuento
        $otrosDesc    = 0.0;
        $detalleOrden = [];
        foreach ($ordenes as $orden) {
            $monto = round((float) ($orden['monto_quincenal'] ?? 0), 2);
            if ($monto > 0) {
                $otrosDesc += $monto;
                $detalleOrden[] = [
                    'concepto'        => $orden['concepto'] ?? 'Descuento',
                    'acreedor'        => $orden['acreedor_nombre'] ?? null,
                    'monto_quincenal' => $monto,
                ];
            }
        }
        $otrosDesc = round($otrosDesc, 2);

        $totalDescEmp = round($afpEmp + $isssEmp + $renta + $otrosDesc, 2);
        $salarioNeto  = round($salarioProp - $totalDescEmp, 2);

        // Cargas patronales
        $afpPat      = $this->calcularAFPPatronal($salarioProp);
        $isssPat     = $this->calcularISSSPatronal($salarioProp);
        $insaforpPat = $this->calcularINSAFORP($salarioProp);
        $totalPat    = round($afpPat + $isssPat + $insaforpPat, 2);
        $costoTotal  = round($salarioProp + $totalPat, 2);

        return [
            'salario_base'             => round($salarioBase, 2),
            'dias_quincena'            => $diasQuincena,
            'dias_laborados'           => round($diasLaborados, 2),
            'salario_proporcional'     => $salarioProp,
            'afp_empleado'             => $afpEmp,
            'isss_empleado'            => $isssEmp,
            'renta'                    => $renta,
            'otros_descuentos'         => $otrosDesc,
            'total_descuentos_empleado'=> $totalDescEmp,
            'salario_neto'             => $salarioNeto,
            'afp_patronal'             => $afpPat,
            'isss_patronal'            => $isssPat,
            'insaforp_patronal'        => $insaforpPat,
            'total_patronal'           => $totalPat,
            'costo_total'              => $costoTotal,
            'detalle_descuentos'       => $detalleOrden,
            'base_renta'               => $baseRenta,
        ];
    }

    /**
     * Totales consolidados de todas las líneas.
     *
     * @param array $lineas  Array de resultados de calcularPlanillaEmpleado
     * @return array
     */
    public function calcularTotalesPlanilla(array $lineas): array
    {
        $totalSalarios   = 0.0;
        $totalDescuentos = 0.0;
        $totalPatronal   = 0.0;
        $totalNeto       = 0.0;

        foreach ($lineas as $linea) {
            $totalSalarios   += (float) ($linea['salario_proporcional']      ?? 0);
            $totalDescuentos += (float) ($linea['total_descuentos_empleado'] ?? 0);
            $totalPatronal   += (float) ($linea['total_patronal']            ?? 0);
            $totalNeto       += (float) ($linea['salario_neto']              ?? 0);
        }

        return [
            'total_salarios'   => round($totalSalarios,   2),
            'total_descuentos' => round($totalDescuentos, 2),
            'total_patronal'   => round($totalPatronal,   2),
            'total_neto'       => round($totalNeto,       2),
        ];
    }
}
