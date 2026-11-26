<?php

namespace App\Services\RRHH;

use App\Models\Asueto;
use App\Models\RRHH\AusenciaInjustificada;
use App\Models\RRHH\DiaSuspension;
use App\Models\RRHH\Incapacidad;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Planilla;
use App\Models\RRHH\PlanillaLinea;
use App\Models\RRHH\Vacacion;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Lógica centralizada para planillas de tipo salarios.
 *
 * Todo ajuste de fórmulas, tipos de movimientos o reglas de negocio
 * para planillas de salarios debe hacerse aquí; los controllers quedan
 * como wrappers de entrada/salida.
 */
class PlanillaSalariosService
{
    // ─── Constantes de fórmulas (Art. CT-SV) ──────────────────────────────────

    /** Horas laborales en un mes estándar (30 días × 8 h). Denominador de tarifa-hora. */
    public const HORAS_MES = 240;

    /** Factor recargo nocturno sobre tarifa-hora base (Art. 161 CT-SV). */
    public const FACTOR_NOCTURNIDAD = 0.25;

    /** Factor de hora extra (el doble de la tarifa normal). */
    public const FACTOR_HORA_EXTRA = 2.0;

    /** Prima de vacaciones sobre la quincena (Art. 177 CT-SV). */
    public const FACTOR_PRIMA_VACACIONES = 0.30;

    public function __construct(private PlanillaCalculatorService $calc) {}

    // ─── Fórmulas atómicas ─────────────────────────────────────────────────────

    public function montoHorasExtras(float $salBase, float $horas): float
    {
        return $horas > 0
            ? round(($salBase / self::HORAS_MES) * self::FACTOR_HORA_EXTRA * $horas, 2)
            : 0.0;
    }

    public function montoNocturnidades(float $salBase, float $horas): float
    {
        return $horas > 0
            ? round(($salBase / self::HORAS_MES) * self::FACTOR_NOCTURNIDAD * $horas, 2)
            : 0.0;
    }

    public function montoAsueto(float $salBase, int $dias): float
    {
        return $dias > 0 ? round(($salBase / 30) * $dias, 2) : 0.0;
    }

    /**
     * Prima de vacaciones = 30 % sobre el salario de la quincena (Art. 177 CT-SV).
     * Solo se paga cuando el empleado cumple su aniversario de ingreso en el período.
     */
    public function primaVacaciones(float $salBase): float
    {
        return round(($salBase / 2) * self::FACTOR_PRIMA_VACACIONES, 2);
    }

    /**
     * Calcula si corresponde pagar prima de vacaciones y retorna el monto.
     * Retorna 0.0 si el empleado no cumple aniversario en la quincena dada.
     */
    public function calcularMontoVacaciones(array $emp, Carbon $fechaInicio, Carbon $fechaFin): float
    {
        $fechaIngreso = $emp['fecha_ingreso'] ?? null;
        if (!$fechaIngreso) return 0.0;

        $salBase = (float) ($emp['salario_base'] ?? 0);
        $ingreso = Carbon::parse($fechaIngreso);

        if ($ingreso->gte($fechaInicio)) return 0.0;

        $anioQ = $fechaInicio->year;
        try {
            $aniversario = Carbon::create($anioQ, $ingreso->month, $ingreso->day);
        } catch (\Exception $e) {
            $aniversario = Carbon::create($anioQ, $ingreso->month, 28);
        }

        return ($aniversario && $aniversario->between($fechaInicio, $fechaFin))
            ? $this->primaVacaciones($salBase)
            : 0.0;
    }

    // ─── Cálculo de línea de empleado ─────────────────────────────────────────

    /**
     * Calcula todos los campos de una línea de planilla para un empleado.
     *
     * @param  array       $emp            Fila del empleado (id, salario_base, es_pensionado,
     *                                     fecha_ingreso, geo_departamento_id, fecha_ingreso_efectiva?)
     * @param  array       $ctx            Contexto cargado por cargarContexto()
     * @param  Carbon      $fechaInicio    Inicio del período de pago
     * @param  Carbon      $fechaFin       Fin del período de pago
     * @param  Carbon|null $hastaEfectivo  Para desvinculados: último día real en la planilla
     * @param  float|null  $extOverride    Override manual de horas extras (null = usar control de horarios)
     * @param  float|null  $noctOverride   Override manual de nocturnidades (null = usar control de horarios)
     * @return array       Listo para merge en PlanillaLinea::create()
     */
    public function calcularLinea(
        array $emp,
        array $ctx,
        Carbon $fechaInicio,
        Carbon $fechaFin,
        ?Carbon $hastaEfectivo = null,
        ?float $extOverride = null,
        ?float $noctOverride = null
    ): array {
        $eid          = (int) $emp['id'];
        $salBase      = (float) ($emp['salario_base'] ?? 0);
        $diasQuincena = $ctx['diasQuincena'];

        $hastaEfectivo = $hastaEfectivo ?? $fechaFin;

        // Empleado que sale de prueba a mitad de quincena: su inicio real en salarios
        $fechaIngresoEfectiva = isset($emp['fecha_ingreso_efectiva'])
            ? Carbon::parse($emp['fecha_ingreso_efectiva'])
            : null;
        $desdeEfectivo = ($fechaIngresoEfectiva && $fechaIngresoEfectiva->gt($fechaInicio))
            ? $fechaIngresoEfectiva
            : $fechaInicio;

        $diasEfectivos = min($diasQuincena, max(0, $desdeEfectivo->diffInDays($hastaEfectivo) + 1));

        $diasNoTrab = $this->calcDiasNoTrabajados(
            $eid,
            $ctx['permisos'], $ctx['incapacidades'], $ctx['vacaciones'],
            $ctx['ausencias'], $ctx['suspensiones'],
            $desdeEfectivo, $hastaEfectivo
        );
        $diasLab = min($diasQuincena, max(0, $diasEfectivos - $diasNoTrab));

        $esPensionado = (bool) ($emp['es_pensionado'] ?? false);
        $resultado = $this->calc->calcularPlanillaEmpleado(
            $salBase, (float) $diasLab, $diasQuincena,
            $ctx['ordenesMap'][$eid]      ?? [],
            $ctx['bonificacionesMap'][$eid] ?? [],
            $esPensionado
        );

        // Asuetos: empleados con horario 'normal' ese día cobran 100% extra
        $geoDptoEmp   = $emp['geo_departamento_id'] ?? null;
        $diasAsueto   = $this->contarAsuetosEmpleado($eid, $geoDptoEmp, $ctx['asuetos'], $ctx['horariosAsueto']);
        $salarioAsueto = $this->montoAsueto($salBase, $diasAsueto);
        $resultado['dias_asueto']    = $diasAsueto;
        $resultado['salario_asueto'] = $salarioAsueto;
        if ($salarioAsueto > 0) {
            $resultado['salario_neto'] = round($resultado['salario_neto'] + $salarioAsueto, 2);
        }

        // Horas extras (override manual tiene prioridad sobre control de horarios)
        $horasExt = $extOverride !== null ? $extOverride : (float) ($ctx['horasExtrasMap'][$eid] ?? 0);
        $montoExt = $this->montoHorasExtras($salBase, $horasExt);
        $resultado['horas_extras']          = round($horasExt, 2);
        $resultado['horas_extras_override'] = $extOverride;
        $resultado['monto_horas_extras']    = $montoExt;
        if ($montoExt > 0) {
            $resultado['salario_neto'] = round($resultado['salario_neto'] + $montoExt, 2);
        }

        // Nocturnidades (Art. 161 CT-SV)
        $horasNoct = $noctOverride !== null ? $noctOverride : (float) ($ctx['nocturnidadesMap'][$eid] ?? 0);
        $montoNoct = $this->montoNocturnidades($salBase, $horasNoct);
        $resultado['horas_nocturnas']          = round($horasNoct, 2);
        $resultado['horas_nocturnas_override'] = $noctOverride;
        $resultado['monto_nocturnidades']      = $montoNoct;
        if ($montoNoct > 0) {
            $resultado['salario_neto'] = round($resultado['salario_neto'] + $montoNoct, 2);
        }

        // Prima de vacaciones (Art. 177 CT-SV)
        $montoVacaciones = $this->calcularMontoVacaciones($emp, $fechaInicio, $fechaFin);
        $resultado['vacaciones'] = $montoVacaciones;
        if ($montoVacaciones > 0) {
            $resultado['salario_neto'] = round($resultado['salario_neto'] + $montoVacaciones, 2);
        }

        // Días de suspensión disciplinaria — se anotan en detalle para trazabilidad
        $diasSusp = $ctx['suspensiones']
            ->filter(fn($s) => $s->amonestacion?->empleado_id === $eid)
            ->count();
        if ($diasSusp > 0) {
            $detalle = json_decode($resultado['detalle_descuentos'] ?? '[]', true) ?: [];
            $detalle[] = [
                'concepto' => "Suspensión disciplinaria ({$diasSusp} día(s))",
                'tipo'     => 'suspension',
                'dias'     => $diasSusp,
                'monto'    => 0,
            ];
            $resultado['detalle_descuentos'] = json_encode($detalle);
        }

        return $resultado;
    }

    /**
     * Recalcula salario_neto de una línea ya guardada (para ajustes post-creación).
     * Suma proporcional + asueto + bonificaciones + extras + nocturnidades + vacaciones − total_descuentos.
     */
    public function recalcularNetoLinea(PlanillaLinea $linea): float
    {
        return round(
            (float) $linea->salario_proporcional
            + (float) ($linea->salario_asueto        ?? 0)
            + (float) ($linea->bonificaciones         ?? 0)
            + (float) ($linea->monto_horas_extras     ?? 0)
            + (float) ($linea->monto_nocturnidades    ?? 0)
            + (float) ($linea->vacaciones             ?? 0)
            - (float) $linea->total_descuentos_empleado,
            2
        );
    }

    // ─── Totales de planilla ───────────────────────────────────────────────────

    public function recalcularTotalesPlanilla(Planilla $planilla): void
    {
        $lineas = PlanillaLinea::where('planilla_id', $planilla->id)->get();

        $totals = [
            'total_salarios'   => $lineas->sum(fn($l) => (float) $l->salario_proporcional),
            'total_descuentos' => $lineas->sum(fn($l) => (float) $l->total_descuentos_empleado),
            'total_patronal'   => $lineas->sum(fn($l) => (float) $l->total_patronal),
            'total_neto'       => $lineas->sum(fn($l) => (float) $l->salario_neto),
        ];

        $planilla->update(array_map(fn($v) => round($v, 2), $totals));
    }

    // ─── Carga de contexto ─────────────────────────────────────────────────────

    /**
     * Carga todos los datos del período necesarios para calcular líneas de empleados.
     * El array retornado se pasa directamente a calcularLinea() y snapshotMovimientosEmpleado().
     *
     * @param  array  $empIds       IDs de los empleados a incluir
     * @param  int    $anio, $mes, $quincena   Período de PAGO
     * @param  Carbon $fechaInicio, $fechaFin  Rango del período de pago
     */
    public function cargarContexto(
        array $empIds,
        int $anio,
        int $mes,
        int $quincena,
        Carbon $fechaInicio,
        Carbon $fechaFin
    ): array {
        // Asueto trabajado se paga en quincena vencida (siguiente período).
        // Buscamos asuetos de la quincena ANTERIOR para pagarlos en ésta.
        [$prevAnio, $prevMes, $prevQ] = $this->calc->quincenaAnterior($anio, $mes, $quincena);
        [$prevInicio, $prevFin]       = $this->calc->rangoQuincena($prevAnio, $prevMes, $prevQ);

        $asuetos = $this->getAsuetosEnRango($prevInicio, $prevFin);

        return [
            'diasQuincena'      => PlanillaCalculatorService::DIAS_QUINCENA,
            'permisos'          => $this->getPermisos($empIds, $fechaInicio, $fechaFin),
            'incapacidades'     => $this->getIncapacidades($empIds, $fechaInicio, $fechaFin),
            'vacaciones'        => $this->getVacaciones($empIds, $fechaInicio, $fechaFin),
            'ausencias'         => $this->getAusencias($empIds, $fechaInicio, $fechaFin),
            'suspensiones'      => $this->getSuspensiones($empIds, $fechaInicio, $fechaFin),
            'asuetos'           => $asuetos,
            'horariosAsueto'    => $this->getHorariosNormalEnAsuetos($empIds, $asuetos),
            'horasExtrasMap'    => $this->getHorasExtrasMap($empIds, $anio, $mes, $quincena),
            'nocturnidadesMap'  => $this->getNocturnidadesMap($empIds, $anio, $mes, $quincena),
            'ordenesMap'        => $this->getOrdenesActivasMap($empIds, $fechaInicio->toDateString(), $quincena),
            'bonificacionesMap' => $this->getBonificacionesMap($empIds, $fechaInicio, $fechaFin),
        ];
    }

    // ─── Resolución de empleados elegibles ────────────────────────────────────

    /**
     * Retorna los empleados activos (y desvinculados en el período) elegibles para
     * una planilla del tipo indicado, con la lógica de periodo de prueba aplicada.
     *
     * @return array ['empleados' => array[], 'desvinculados' => Collection]
     */
    public function resolverEmpleados(
        ?int $sucursalId,
        Carbon $fechaInicio,
        Carbon $fechaFin,
        string $tipoPlanilla = 'salarios'
    ): array {
        $transicionandoMap = collect();

        $fechaInicioStr = $fechaInicio->toDateString();
        $fechaFinStr    = $fechaFin->toDateString();

        $desvinculados = DB::connection('rrhh')
            ->table('desvinculaciones')
            ->where('estado', 'aprobado')
            ->where('fecha_efectiva', '>=', $fechaInicioStr)
            ->where('fecha_efectiva', '<=', $fechaFinStr)
            ->select('empleado_id', 'fecha_efectiva')
            ->get()
            ->keyBy('empleado_id');

        $desvinculadosIds = $desvinculados->keys()->all();

        $query = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('sucursales as s', 's.id', '=', 'e.sucursal_id')
            ->where(function ($q) use ($desvinculadosIds) {
                $q->where('e.activo', true);
                if (!empty($desvinculadosIds)) {
                    $q->orWhereIn('e.id', $desvinculadosIds);
                }
            })
            ->select('e.id', 'e.salario_base', 's.geo_departamento_id', 'e.es_pensionado', 'e.fecha_ingreso');

        if ($tipoPlanilla === 'honorarios') {
            $honorariosIds = DB::table('empleado_tipo_planilla as etp')
                ->join('tipo_planilla as tp', 'tp.id', '=', 'etp.tipo_planilla_id')
                ->where('tp.slug', 'honorarios')
                ->where('etp.fecha_inicio', '<=', $fechaFinStr)
                ->where(function ($q) use ($fechaInicioStr) {
                    $q->whereNull('etp.fecha_fin')
                      ->orWhere('etp.fecha_fin', '>=', $fechaInicioStr);
                })
                ->pluck('etp.empleado_id');

            $query->whereIn('e.id', $honorariosIds);
        } else {
            $pruebas = DB::connection('rrhh')
                ->table('periodos_prueba')
                ->where('estado', 'en_prueba')
                ->select('empleado_id', 'fecha_fin_estimada')
                ->get()
                ->keyBy('empleado_id');

            $excluirPruebaIds = $pruebas
                ->filter(fn($p) => $p->fecha_fin_estimada >= $fechaFinStr)
                ->keys()->toArray();

            $transicionandoMap = $pruebas
                ->filter(fn($p) => $p->fecha_fin_estimada >= $fechaInicioStr && $p->fecha_fin_estimada < $fechaFinStr)
                ->mapWithKeys(fn($p) => [
                    (int) $p->empleado_id => Carbon::parse($p->fecha_fin_estimada)->addDay()->toDateString()
                ]);

            $query->join('empleado_tipo_planilla as etp', 'etp.empleado_id', '=', 'e.id')
                  ->join('tipo_planilla as tp', function ($join) use ($tipoPlanilla) {
                      $join->on('tp.id', '=', 'etp.tipo_planilla_id')
                           ->where('tp.slug', '=', $tipoPlanilla);
                  })
                  ->where('etp.fecha_inicio', '<=', $fechaFinStr)
                  ->where(function ($q) use ($fechaInicioStr) {
                      $q->whereNull('etp.fecha_fin')
                        ->orWhere('etp.fecha_fin', '>=', $fechaInicioStr);
                  });

            if (!empty($excluirPruebaIds)) {
                $query->whereNotIn('e.id', $excluirPruebaIds);
            }
        }

        if ($sucursalId) {
            $query->where('e.sucursal_id', $sucursalId);
        }

        $empleados = $query->get()->map(function ($r) use ($transicionandoMap) {
            $r = (array) $r;
            if (isset($transicionandoMap[(int) $r['id']])) {
                $r['fecha_ingreso_efectiva'] = $transicionandoMap[(int) $r['id']];
            }
            return $r;
        })->all();

        return [
            'empleados'     => $empleados,
            'desvinculados' => $desvinculados,
        ];
    }

    // ─── Snapshot de movimientos ───────────────────────────────────────────────

    /**
     * Regenera el snapshot de movimientos para toda la planilla.
     * Borra solo los movimientos 'activo'; los 'anulado' se conservan.
     */
    public function snapshotMovimientos(
        int $planillaId,
        array $empleados,
        array $ctx,
        Carbon $fechaInicio,
        Carbon $fechaFin
    ): void {
        DB::connection('rrhh')
            ->table('planilla_movimientos')
            ->where('planilla_id', $planillaId)
            ->where('estado', 'activo')
            ->delete();

        foreach ($empleados as $emp) {
            $this->snapshotMovimientosEmpleado(
                $planillaId,
                (int) $emp['id'],
                (float) ($emp['salario_base'] ?? 0),
                $emp['fecha_ingreso'] ?? null,
                $ctx,
                $fechaInicio,
                $fechaFin
            );
        }
    }

    /**
     * Genera el snapshot de movimientos para un único empleado.
     * Respeta los movimientos 'anulado' existentes (no los recrea ni modifica).
     */
    public function snapshotMovimientosEmpleado(
        int $planillaId,
        int $eid,
        float $salBase,
        ?string $fechaIngreso,
        array $ctx,
        Carbon $fechaInicio,
        Carbon $fechaFin
    ): void {
        // Fingerprints de movimientos anulados: no se recrean ni se modifican
        $anuladosSet = DB::connection('rrhh')
            ->table('planilla_movimientos')
            ->where('planilla_id', $planillaId)
            ->where('empleado_id', $eid)
            ->where('estado', 'anulado')
            ->get(['tipo', 'referencia_id', 'concepto'])
            ->mapWithKeys(fn($r) => [$r->tipo . '|' . ($r->referencia_id ?? $r->concepto) => true])
            ->all();

        DB::connection('rrhh')
            ->table('planilla_movimientos')
            ->where('planilla_id', $planillaId)
            ->where('empleado_id', $eid)
            ->where('estado', 'activo')
            ->delete();

        $rows = [];

        // Permisos
        foreach ($ctx['permisos']->where('empleado_id', $eid) as $p) {
            $categoria = $p->tipoPermiso?->categoria ?? 'con_goce';
            $tipo      = $categoria === 'sin_goce' ? 'permiso_sin_goce' : 'permiso_con_goce';
            $dias      = (float) ($p->dias ?? ($p->horas_solicitadas ? $p->horas_solicitadas / 8 : 0));
            if ($dias <= 0) continue;
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => $tipo,
                'concepto'     => $p->tipoPermiso?->nombre ?? 'Permiso',
                'fecha_inicio' => $p->fecha,
                'fecha_fin'    => $p->fecha,
                'dias'         => $categoria === 'sin_goce' ? -$dias : 0,
                'monto'        => null,
                'referencia_id'=> $p->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Incapacidades
        foreach ($ctx['incapacidades']->where('empleado_id', $eid) as $inc) {
            $diasEnQ = $this->diasSolapados($inc->fecha_inicio, $inc->fecha_fin, $fechaInicio, $fechaFin);
            if ($diasEnQ <= 0) continue;
            $diasDesc = $inc->tipo_institucion === 'privada'
                ? $diasEnQ
                : max(0, min((int) $inc->dias - 3, $diasEnQ));
            $tipo = $inc->tipo_institucion === 'isss' ? 'incapacidad_isss' : 'incapacidad_privada';
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => $tipo,
                'concepto'     => 'Incapacidad ' . strtoupper($inc->tipo_institucion ?? ''),
                'fecha_inicio' => $inc->fecha_inicio,
                'fecha_fin'    => $inc->fecha_fin,
                'dias'         => -$diasDesc,
                'monto'        => null,
                'referencia_id'=> $inc->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Vacaciones
        foreach ($ctx['vacaciones']->where('empleado_id', $eid) as $v) {
            $diasEnQ = $this->diasSolapados($v->fecha_inicio, $v->fecha_fin, $fechaInicio, $fechaFin);
            if ($diasEnQ <= 0) continue;
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'vacacion',
                'concepto'     => 'Vacaciones',
                'fecha_inicio' => $v->fecha_inicio,
                'fecha_fin'    => $v->fecha_fin,
                'dias'         => -$diasEnQ,
                'monto'        => null,
                'referencia_id'=> $v->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Ausencias injustificadas
        foreach ($ctx['ausencias']->where('empleado_id', $eid) as $a) {
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'ausencia',
                'concepto'     => 'Ausencia injustificada',
                'fecha_inicio' => $a->fecha,
                'fecha_fin'    => $a->fecha,
                'dias'         => -1,
                'monto'        => null,
                'referencia_id'=> $a->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Suspensiones disciplinarias
        foreach ($ctx['suspensiones']->filter(fn($s) => $s->amonestacion?->empleado_id === $eid) as $s) {
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'suspension',
                'concepto'     => 'Suspensión disciplinaria',
                'fecha_inicio' => $s->fecha,
                'fecha_fin'    => $s->fecha,
                'dias'         => -1,
                'monto'        => null,
                'referencia_id'=> $s->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Asuetos (positivos — trabajó en día de asueto)
        $geoDptoEmp = null; // No disponible aquí; se omite filtro departamental para el snapshot
        foreach ($ctx['asuetos'] as $asueto) {
            $fechaStr = $asueto->fecha instanceof Carbon
                ? $asueto->fecha->toDateString()
                : substr((string) $asueto->fecha, 0, 10);
            $fechasNormal = $ctx['horariosAsueto'][$eid] ?? [];
            if (!in_array($fechaStr, $fechasNormal)) continue;
            $montoAsueto = round(($salBase / 30), 2);
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'asueto',
                'concepto'     => 'Asueto trabajado',
                'fecha_inicio' => $fechaStr,
                'fecha_fin'    => $fechaStr,
                'dias'         => 1,
                'monto'        => $montoAsueto,
                'referencia_id'=> $asueto->id,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Horas extras aprobadas (tarifa doble, Art. 161 CT-SV)
        $horasExt = (float) ($ctx['horasExtrasMap'][$eid] ?? 0);
        if ($horasExt > 0) {
            $montoExt = $this->montoHorasExtras($salBase, $horasExt);
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'horas_extras',
                'concepto'     => "Horas extras aprobadas ({$horasExt}h)",
                'fecha_inicio' => null,
                'fecha_fin'    => null,
                'dias'         => 0,
                'monto'        => $montoExt,
                'referencia_id'=> null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Nocturnidades (25% sobre tarifa hora base, Art. 161 CT-SV)
        $horasNoct = (float) ($ctx['nocturnidadesMap'][$eid] ?? 0);
        if ($horasNoct > 0) {
            $montoNoct = $this->montoNocturnidades($salBase, $horasNoct);
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'nocturnidad',
                'concepto'     => "Recargo nocturno ({$horasNoct}h)",
                'fecha_inicio' => null,
                'fecha_fin'    => null,
                'dias'         => 0,
                'monto'        => $montoNoct,
                'referencia_id'=> null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Órdenes de descuento activas
        foreach ($ctx['ordenesMap'][$eid] ?? [] as $ord) {
            $monto = (float) $ord['monto_quincenal'];
            if ($monto <= 0) continue;
            $acreedor = $ord['acreedor_nombre'] ?? null;
            $concepto = $ord['concepto'] ?? 'Orden de descuento';
            $rows[] = [
                'planilla_id'  => $planillaId,
                'empleado_id'  => $eid,
                'tipo'         => 'orden_descuento',
                'concepto'     => $acreedor ? "{$acreedor} — {$concepto}" : $concepto,
                'fecha_inicio' => null,
                'fecha_fin'    => null,
                'dias'         => 0,
                'monto'        => -$monto,
                'referencia_id'=> $ord['acreedor_id'] ?? null,
                'created_at'   => now(),
                'updated_at'   => now(),
            ];
        }

        // Pago de vacaciones al cumplir aniversario (Art. 177 CT-SV)
        if ($fechaIngreso) {
            $ingreso = Carbon::parse($fechaIngreso);
            if ($ingreso->lt($fechaInicio)) {
                $anioQ = $fechaInicio->year;
                try {
                    $aniversario = Carbon::create($anioQ, $ingreso->month, $ingreso->day);
                } catch (\Exception $e) {
                    $aniversario = Carbon::create($anioQ, $ingreso->month, 28);
                }
                if ($aniversario && $aniversario->between($fechaInicio, $fechaFin)) {
                    $montoVacaciones = $this->primaVacaciones($salBase);
                    $rows[] = [
                        'planilla_id'   => $planillaId,
                        'empleado_id'   => $eid,
                        'tipo'          => 'pago_vacaciones',
                        'concepto'      => "Pago vacaciones ({$anioQ})",
                        'fecha_inicio'  => $aniversario->toDateString(),
                        'fecha_fin'     => $aniversario->toDateString(),
                        'dias'          => 0,
                        'monto'         => $montoVacaciones,
                        'referencia_id' => null,
                        'created_at'    => now(),
                        'updated_at'    => now(),
                    ];
                }
            }
        }

        // Filtrar los que ya tienen un movimiento anulado con el mismo fingerprint
        $rows = array_values(array_filter($rows, function ($row) use ($anuladosSet) {
            $key = $row['tipo'] . '|' . ($row['referencia_id'] ?? $row['concepto']);
            return !isset($anuladosSet[$key]);
        }));

        if (!empty($rows)) {
            DB::connection('rrhh')->table('planilla_movimientos')->insert($rows);
        }
    }

    // ─── Consultas de datos del período ───────────────────────────────────────

    public function getPermisos(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Permiso::with('tipoPermiso')
            ->whereIn('empleado_id', $empIds)
            ->where('estado', 'aprobado')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();
    }

    public function getIncapacidades(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Incapacidad::whereIn('empleado_id', $empIds)
            ->where('fecha_inicio', '<=', $hasta->toDateString())
            ->where('fecha_fin',    '>=', $desde->toDateString())
            ->get();
    }

    public function getVacaciones(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Vacacion::whereIn('empleado_id', $empIds)
            ->where('estado', 'aprobado')
            ->where('fecha_inicio', '<=', $hasta->toDateString())
            ->where('fecha_fin',    '>=', $desde->toDateString())
            ->get();
    }

    public function getAusencias(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return AusenciaInjustificada::whereIn('empleado_id', $empIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();
    }

    public function getSuspensiones(array $empIds, Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        if (empty($empIds)) return collect([]);

        return DiaSuspension::with('amonestacion')
            ->whereHas('amonestacion', fn($q) => $q
                ->whereIn('empleado_id', $empIds)
                ->where('estado', 'aprobado')
                ->where('aplica_suspension', true)
                ->where('invalidada', false)
            )
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();
    }

    public function getAsuetosEnRango(Carbon $desde, Carbon $hasta): \Illuminate\Support\Collection
    {
        return Asueto::where('activo', true)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get(['id', 'fecha', 'tipo', 'geo_departamento_id']);
    }

    public function getHorariosNormalEnAsuetos(array $empIds, \Illuminate\Support\Collection $asuetos): array
    {
        if (empty($empIds) || $asuetos->isEmpty()) return [];

        $fechas = $asuetos
            ->map(fn($a) => $a->fecha instanceof Carbon ? $a->fecha->toDateString() : substr((string) $a->fecha, 0, 10))
            ->unique()->values()->all();

        $map = [];
        DB::connection('pgsql')
            ->table('horarios_empleado')
            ->whereIn('empleado_id', $empIds)
            ->whereIn('fecha', $fechas)
            ->where('tipo', 'normal')
            ->select('empleado_id', 'fecha')
            ->get()
            ->each(function ($r) use (&$map) {
                $map[(int) $r->empleado_id][] = substr((string) $r->fecha, 0, 10);
            });

        return $map;
    }

    public function contarAsuetosEmpleado(
        int $empId,
        ?int $geoDptoId,
        \Illuminate\Support\Collection $asuetos,
        array $horariosAsueto
    ): int {
        $fechasNormal = $horariosAsueto[$empId] ?? [];
        if (empty($fechasNormal)) return 0;

        $count = 0;
        foreach ($asuetos as $asueto) {
            $fecha = $asueto->fecha instanceof Carbon
                ? $asueto->fecha->toDateString()
                : substr((string) $asueto->fecha, 0, 10);

            $aplica = $asueto->tipo === 'nacional'
                || ($asueto->tipo === 'departamental'
                    && $geoDptoId
                    && (int) $asueto->geo_departamento_id === $geoDptoId);

            if ($aplica && in_array($fecha, $fechasNormal)) {
                $count++;
            }
        }
        return $count;
    }

    public function getOrdenesActivasMap(array $empIds, string $fecha, int $quincena = 1): array
    {
        if (empty($empIds)) return [];

        $montoCol = $quincena === 1 ? 'monto_q1' : 'monto_q2';

        $rows = DB::connection('pgsql')
            ->table('ordenes_descuento as od')
            ->join('estados_orden_descuento as e', 'e.id', '=', 'od.estado_id')
            ->join('acreedores as a', 'a.id', '=', 'od.acreedor_id')
            ->whereIn('od.empleado_id', $empIds)
            ->where('e.nombre', 'Activa')
            ->where('od.fecha_inicio', '<=', $fecha)
            ->where(function ($q) use ($fecha) {
                $q->whereNull('od.fecha_fin')
                  ->orWhere('od.fecha_fin', '>=', $fecha);
            })
            ->select(
                'od.empleado_id',
                'od.id as orden_id',
                'od.acreedor_id',
                'od.referencia',
                DB::raw("od.{$montoCol} as monto_quincenal"),
                'a.nombre as acreedor_nombre'
            )
            ->get();

        $map = [];
        foreach ($rows as $o) {
            $eid   = (int) $o->empleado_id;
            $monto = (float) $o->monto_quincenal;
            if ($monto <= 0) continue;
            if (!isset($map[$eid])) $map[$eid] = [];
            $map[$eid][] = [
                'concepto'        => $o->referencia ?? 'Orden de descuento',
                'monto_quincenal' => $monto,
                'acreedor_nombre' => $o->acreedor_nombre,
                'acreedor_id'     => (int) $o->acreedor_id,
                'orden_id'        => (int) $o->orden_id,
            ];
        }
        return $map;
    }

    public function getBonificacionesMap(array $empIds, Carbon $desde, Carbon $hasta): array
    {
        if (empty($empIds)) return [];

        $rows = DB::connection('pgsql')
            ->table('bonificaciones as b')
            ->join('estados_bonificacion as e', 'e.id', '=', 'b.estado_id')
            ->join('tipos_bonificacion as t', 't.id', '=', 'b.tipo_bonificacion_id')
            ->whereIn('b.empleado_id', $empIds)
            ->where('e.nombre', 'Aprobada')
            ->whereBetween('b.fecha_aplicar', [$desde->toDateString(), $hasta->toDateString()])
            ->select('b.id', 'b.empleado_id', 'b.monto', 'b.descripcion', 't.nombre as tipo_nombre')
            ->get();

        $map = [];
        foreach ($rows as $b) {
            $eid = (int) $b->empleado_id;
            if (!isset($map[$eid])) $map[$eid] = [];
            $map[$eid][] = [
                'id'       => $b->id,
                'concepto' => $b->descripcion ?: $b->tipo_nombre,
                'monto'    => (float) $b->monto,
            ];
        }
        return $map;
    }

    public function getHorasExtrasMap(array $empIds, int $anioP, int $mesP, int $quincenaP): array
    {
        if (empty($empIds)) return [];

        if ($quincenaP === 1) {
            $mesTrab  = $mesP === 1 ? 12 : $mesP - 1;
            $anioTrab = $mesP === 1 ? $anioP - 1 : $anioP;
            $desde    = Carbon::create($anioTrab, $mesTrab, 16);
            $hasta    = Carbon::create($anioTrab, $mesTrab)->endOfMonth()->startOfDay();
        } else {
            $desde = Carbon::create($anioP, $mesP, 1);
            $hasta = Carbon::create($anioP, $mesP, 15);
        }

        $horarios = DB::connection('pgsql')
            ->table('horarios_empleado')
            ->whereIn('empleado_id', $empIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->where('tipo', 'normal')
            ->whereNotNull('hora_inicio')
            ->whereNotNull('hora_fin')
            ->get(['empleado_id', 'fecha', 'hora_inicio', 'hora_fin']);

        $toMins  = fn(string $t): int => (int) explode(':', $t)[0] * 60 + (int) (explode(':', $t)[1] ?? 0);
        $horasNoct = function (int $i, int $f): float {
            $n = 0;
            $z2s = max($i, 19 * 60); $z2e = min($f, 24 * 60);
            if ($z2e > $z2s) $n += $z2e - $z2s;
            if ($f > 24 * 60) $n += min($f, 30 * 60) - 24 * 60;
            return $n / 60.0;
        };

        $grouped = [];
        foreach ($horarios as $h) {
            $grouped[(int) $h->empleado_id][$h->fecha][] = $h;
        }

        $result = [];
        foreach ($grouped as $eid => $dias) {
            $result[$eid] = 0.0;
            foreach ($dias as $turnos) {
                $totalH = 0.0; $totalN = 0.0;
                foreach ($turnos as $t) {
                    $i = $toMins($t->hora_inicio); $f = $toMins($t->hora_fin);
                    if ($f <= $i) $f += 1440;
                    $totalH += ($f - $i) / 60.0;
                    $totalN += $horasNoct($i, $f);
                }
                $maxHoras = $totalN > 4 ? 7 : 8;
                $result[$eid] += max(0.0, $totalH - $maxHoras);
            }
        }

        return array_map(fn($h) => round($h, 2), $result);
    }

    public function getNocturnidadesMap(array $empIds, int $anioP, int $mesP, int $quincenaP): array
    {
        if (empty($empIds)) return [];

        if ($quincenaP === 1) {
            $mesTrab  = $mesP === 1 ? 12 : $mesP - 1;
            $anioTrab = $mesP === 1 ? $anioP - 1 : $anioP;
            $desde    = Carbon::create($anioTrab, $mesTrab, 16);
            $hasta    = Carbon::create($anioTrab, $mesTrab)->endOfMonth()->startOfDay();
        } else {
            $desde = Carbon::create($anioP, $mesP, 1);
            $hasta = Carbon::create($anioP, $mesP, 15);
        }

        $horarios = DB::connection('pgsql')
            ->table('horarios_empleado')
            ->whereIn('empleado_id', $empIds)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->where('tipo', 'normal')
            ->whereNotNull('hora_inicio')
            ->whereNotNull('hora_fin')
            ->get(['empleado_id', 'hora_inicio', 'hora_fin']);

        $result  = [];
        $toMins  = fn(string $t): int => (int) explode(':', $t)[0] * 60 + (int) (explode(':', $t)[1] ?? 0);

        foreach ($horarios as $h) {
            $eid    = (int) $h->empleado_id;
            $inicio = $toMins($h->hora_inicio);
            $fin    = $toMins($h->hora_fin);
            if ($fin <= $inicio) $fin += 1440;

            $noct = 0;
            $z1s = max($inicio, 19 * 60); $z1e = min($fin, 24 * 60);
            if ($z1e > $z1s) $noct += $z1e - $z1s;
            if ($fin > 1440) $noct += min($fin, 1440 + 6 * 60) - 1440;

            $result[$eid] = ($result[$eid] ?? 0.0) + $noct / 60.0;
        }

        return array_map(fn($h) => round($h, 2), $result);
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Calcula días no trabajados (con descuento) para un empleado en la quincena.
     * Las vacaciones NO descuentan del salario proporcional (Art. 177 CT-SV).
     */
    public function calcDiasNoTrabajados(
        int $eid,
        $permisos, $incapacidades, $vacaciones, $ausencias, $suspensiones,
        Carbon $desde, Carbon $hasta
    ): float {
        $total = 0.0;

        foreach ($permisos->where('empleado_id', $eid) as $p) {
            if ($p->tipoPermiso && $p->tipoPermiso->categoria === 'sin_goce') {
                $total += (float) ($p->dias ?? ($p->horas_solicitadas ? $p->horas_solicitadas / 8 : 0));
            }
        }

        foreach ($incapacidades->where('empleado_id', $eid) as $inc) {
            $diasEnQ = $this->diasSolapados($inc->fecha_inicio, $inc->fecha_fin, $desde, $hasta);
            if ($inc->tipo_institucion === 'privada') {
                $total += $diasEnQ;
            } elseif ($inc->tipo_institucion === 'isss') {
                $total += max(0, min((int) $inc->dias - 3, $diasEnQ));
            }
        }

        // Las vacaciones NO descuentan del salario proporcional (Art. 177 CT-SV).

        foreach ($ausencias->where('empleado_id', $eid) as $a) {
            $total += 1;
        }

        $total += $suspensiones
            ->filter(fn($s) => $s->amonestacion?->empleado_id === $eid)
            ->count();

        return round($total, 2);
    }

    public function diasSolapados($inicio, $fin, Carbon $desde, Carbon $hasta): int
    {
        $start = max(Carbon::parse($inicio), $desde);
        $end   = min(Carbon::parse($fin), $hasta);
        return max(0, $start->diffInDays($end) + 1);
    }
}
