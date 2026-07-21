<?php

namespace App\Services\RRHH;

use App\Models\RRHH\AusenciaInjustificada;
use App\Models\RRHH\DiaSuspension;
use App\Models\RRHH\Incapacidad;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\PropinaAdicionalConfig;
use App\Models\RRHH\PropinaConfigSucursal;
use App\Models\RRHH\PropinaDetalle;
use App\Models\RRHH\PropinaPeriodo;
use App\Models\RRHH\PropinaPuntosCargo;
use App\Models\RRHH\PropinaPuntosEmpleado;
use App\Models\RRHH\PropinaSobrante;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class PropinaCalculatorService
{
    /**
     * Resuelve los puntos de propina de un empleado en una fecha dada.
     * Prioridad: override vigente > default del cargo > 1.0
     */
    public function resolverPuntos(int $empleadoId, int $cargoId, Carbon $fecha): array
    {
        $override = PropinaPuntosEmpleado::where('empleado_id', $empleadoId)
            ->where('fecha_desde', '<=', $fecha->toDateString())
            ->where(fn($q) => $q->whereNull('fecha_hasta')->orWhere('fecha_hasta', '>=', $fecha->toDateString()))
            ->orderByDesc('fecha_desde')
            ->first();

        if ($override) {
            return ['puntos' => (float) $override->puntos_propina, 'fuente' => 'override'];
        }

        $puntoCargo = PropinaPuntosCargo::where('cargo_id', $cargoId)->first();
        if ($puntoCargo) {
            return ['puntos' => (float) $puntoCargo->puntos_propina, 'fuente' => 'cargo'];
        }

        return ['puntos' => 1.0, 'fuente' => 'default'];
    }

    /**
     * Calcula días no laborados de un empleado en el período de quincena.
     * Descuenta: permisos sin_goce, incapacidades (privada=total, ISSS=días-3),
     * ausencias injustificadas, suspensiones disciplinarias.
     * NO descuenta vacaciones (son licencia pagada).
     */
    public function calcDiasNoLaborados(
        int $empleadoId,
        Carbon $desde,
        Carbon $hasta
    ): array {
        $total = 0.0;
        $detalle = [];

        // Permisos sin goce
        $permisos = Permiso::with('tipoPermiso')
            ->where('empleado_id', $empleadoId)
            ->where('estado', 'aprobado')
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();

        foreach ($permisos as $p) {
            if ($p->tipoPermiso && $p->tipoPermiso->categoria === 'sin_goce') {
                $dias = (float) ($p->dias ?? ($p->horas_solicitadas ? $p->horas_solicitadas / 8 : 0));
                if ($dias > 0) {
                    $total += $dias;
                    $detalle[] = ['tipo' => 'permiso_sin_goce', 'dias' => $dias, 'fecha' => $p->fecha?->toDateString()];
                }
            }
        }

        // Incapacidades
        $incapacidades = Incapacidad::where('empleado_id', $empleadoId)
            ->where('fecha_inicio', '<=', $hasta->toDateString())
            ->where('fecha_fin', '>=', $desde->toDateString())
            ->get();

        foreach ($incapacidades as $inc) {
            $diasEnQ = $this->diasSolapados(
                Carbon::parse($inc->fecha_inicio),
                Carbon::parse($inc->fecha_fin),
                $desde, $hasta
            );
            if ($inc->tipo_institucion === 'privada') {
                if ($diasEnQ > 0) {
                    $total += $diasEnQ;
                    $detalle[] = ['tipo' => 'incapacidad_privada', 'dias' => $diasEnQ];
                }
            } elseif ($inc->tipo_institucion === 'isss') {
                $diasDesc = max(0, min((int) $inc->dias - 3, $diasEnQ));
                if ($diasDesc > 0) {
                    $total += $diasDesc;
                    $detalle[] = ['tipo' => 'incapacidad_isss', 'dias' => $diasDesc];
                }
            }
        }

        // Ausencias injustificadas
        $ausencias = AusenciaInjustificada::where('empleado_id', $empleadoId)
            ->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])
            ->get();

        foreach ($ausencias as $a) {
            $total += 1;
            $detalle[] = ['tipo' => 'ausencia_injustificada', 'dias' => 1, 'fecha' => $a->fecha instanceof Carbon ? $a->fecha->toDateString() : $a->fecha];
        }

        // Suspensiones disciplinarias
        $suspensiones = DiaSuspension::whereHas('amonestacion', fn($q) => $q
            ->where('empleado_id', $empleadoId)
            ->where('estado', 'aprobado')
            ->where('aplica_suspension', true)
            ->where('invalidada', false)
        )->whereBetween('fecha', [$desde->toDateString(), $hasta->toDateString()])->count();

        if ($suspensiones > 0) {
            $total += $suspensiones;
            $detalle[] = ['tipo' => 'suspension', 'dias' => $suspensiones];
        }

        return ['dias_no_laborados' => round($total, 2), 'detalle' => $detalle];
    }

    /**
     * Días solapados entre un rango [inicio, fin] y la quincena [desde, hasta].
     */
    private function diasSolapados(Carbon $inicio, Carbon $fin, Carbon $desde, Carbon $hasta): int
    {
        $solapado_inicio = $inicio->max($desde);
        $solapado_fin    = $fin->min($hasta);
        if ($solapado_fin < $solapado_inicio) return 0;
        return $solapado_inicio->diffInDays($solapado_fin) + 1;
    }

    /**
     * Calcula los días efectivos de un empleado en el período considerando
     * fecha de ingreso (si entró dentro de la quincena) y fecha de baja.
     * Retorna días máximos disponibles (antes de restar ausencias).
     */
    public function diasMaxDisponibles(int $empleadoId, Carbon $desde, Carbon $hasta): int
    {
        $emp = DB::connection('pgsql')
            ->table('empleados')
            ->where('id', $empleadoId)
            ->select('fecha_ingreso', 'fecha_baja')
            ->first();

        $inicio = $desde->copy();
        $fin    = $hasta->copy();

        if ($emp) {
            if ($emp->fecha_ingreso) {
                $fi = Carbon::parse($emp->fecha_ingreso);
                if ($fi->gt($hasta)) return 0; // No había ingresado
                if ($fi->gt($inicio)) $inicio = $fi;
            }
            if (!empty($emp->fecha_baja)) {
                $fb = Carbon::parse($emp->fecha_baja);
                if ($fb->lt($desde)) return 0; // Ya había salido
                if ($fb->lt($fin)) $fin = $fb;
            }
        }

        return max(0, $inicio->diffInDays($fin) + 1);
    }

    /**
     * Obtiene el sobrante pendiente acumulado de la sucursal (de períodos anteriores).
     */
    public function getSobrantePendiente(int $sucursalId): float
    {
        return (float) PropinaSobrante::where('sucursal_id', $sucursalId)
            ->whereIn('estado', ['pendiente', 'parcial'])
            ->sum('monto_pendiente');
    }

    /**
     * Resuelve la propina adicional configurada para cargo+sucursal.
     */
    public function resolverAdicional(int $cargoId, int $sucursalId): float
    {
        // Buscar config específica de cargo+sucursal, luego solo cargo
        $config = PropinaAdicionalConfig::where('activa', true)
            ->where('cargo_id', $cargoId)
            ->where('sucursal_id', $sucursalId)
            ->first()
            ?? PropinaAdicionalConfig::where('activa', true)
                ->where('cargo_id', $cargoId)
                ->whereNull('sucursal_id')
                ->first();

        return $config ? (float) $config->monto : 0.0;
    }

    /**
     * Genera o recalcula todos los detalles de un período.
     * Requiere que el período ya tenga venta_quincena y propina_total_recolectada.
     */
    public function calcularPeriodo(PropinaPeriodo $periodo): PropinaPeriodo
    {
        $config = PropinaConfigSucursal::where('sucursal_id', $periodo->sucursal_id)->first();
        $pctPropina  = $config ? (float) $config->pct_propina_sobre_venta      : 0.10;
        $pctDist     = $config ? (float) $config->pct_distribucion_empleados    : 0.65;
        $diasQ       = $periodo->dias_quincena;

        $desde = Carbon::parse($periodo->fecha_inicio);
        $hasta = Carbon::parse($periodo->fecha_fin);

        // Cargar empleados activos de la sucursal en el período
        $empleados = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'c.id', '=', 'e.cargo_id')
            ->where('e.sucursal_id', $periodo->sucursal_id)
            ->where(fn($q) => $q
                ->whereNull('e.fecha_baja')
                ->orWhere('e.fecha_baja', '>=', $desde->toDateString())
            )
            ->where(fn($q) => $q
                ->whereNull('e.fecha_ingreso')
                ->orWhere('e.fecha_ingreso', '<=', $hasta->toDateString())
            )
            ->select('e.id as empleado_id', 'e.cargo_id', 'c.nombre as cargo_nombre',
                     'e.fecha_ingreso', 'e.fecha_baja')
            ->get();

        // Calcular puntos totales
        $puntosTotal = 0.0;
        $empDatos = [];

        foreach ($empleados as $emp) {
            $puntoRes = $this->resolverPuntos($emp->empleado_id, $emp->cargo_id, $desde);
            $diasMax  = $this->diasMaxDisponibles($emp->empleado_id, $desde, $hasta);
            if ($diasMax <= 0) continue; // No estuvo en el período

            $puntosTotal += $puntoRes['puntos'];
            $empDatos[] = [
                'empleado_id' => $emp->empleado_id,
                'cargo_id'    => $emp->cargo_id,
                'puntos'      => $puntoRes['puntos'],
                'fuente'      => $puntoRes['fuente'],
                'dias_max'    => $diasMax,
            ];
        }

        // Calcular propina tabla y valor punto
        $ventaSinIva   = (float) $periodo->venta_quincena;
        $propinaTabla  = round($ventaSinIva * $pctPropina * $pctDist, 2);
        $valorPunto    = $puntosTotal > 0 ? round($propinaTabla / $puntosTotal, 4) : 0.0;

        // Sobrante pendiente de períodos anteriores
        $sobrantePendiente = $this->getSobrantePendiente($periodo->sucursal_id);

        // Calcular y upsert detalles por empleado
        $totalRepartido = 0.0;

        foreach ($empDatos as $emp) {
            $ausencias = $this->calcDiasNoLaborados($emp['empleado_id'], $desde, $hasta);
            $diasNoLab = min($ausencias['dias_no_laborados'], $emp['dias_max']);
            $diasLab   = max(0, $emp['dias_max'] - $diasNoLab);

            $propinaDiaria  = $diasQ > 0 ? round($valorPunto * $emp['puntos'] / $diasQ, 4) : 0.0;
            $propinaQ       = round($propinaDiaria * $diasLab, 2);
            $adicional      = $this->resolverAdicional($emp['cargo_id'], $periodo->sucursal_id);
            $total          = round($propinaQ + $adicional, 2);

            $totalRepartido += $total;

            PropinaDetalle::updateOrCreate(
                ['periodo_id' => $periodo->id, 'empleado_id' => $emp['empleado_id']],
                [
                    'puntos_propina'    => $emp['puntos'],
                    'fuente_puntos'     => $emp['fuente'],
                    'dias_quincena'     => $diasQ,
                    'dias_no_laborados' => $diasNoLab,
                    'dias_laborados'    => $diasLab,
                    'detalle_ausencias' => $ausencias['detalle'],
                    'propina_diaria'    => $propinaDiaria,
                    'propina_quincena'  => $propinaQ,
                    'sobrante_aplicado' => 0,
                    'propina_adicional' => $adicional,
                    'total_propina'     => $total,
                    'incluido'          => true,
                ]
            );
        }

        // Totales del período
        $recolectada    = (float) $periodo->propina_total_recolectada;
        $retencion      = round($recolectada - $totalRepartido, 2);
        $excedente      = round($totalRepartido - $propinaTabla, 2);
        $sobrGen        = max(0.0, round($recolectada - $propinaTabla - $retencion, 2));

        $periodo->update([
            'propina_tabla'           => $propinaTabla,
            'puntos_totales'          => round($puntosTotal, 2),
            'valor_punto_propina'     => $valorPunto,
            'propina_repartida'       => round($totalRepartido, 2),
            'retencion_monto'         => max(0, $retencion),
            'retencion_pct'           => $recolectada > 0 ? round(max(0, $retencion) / $recolectada, 4) : 0,
            'excedente_vs_tabla'      => $excedente,
            'sobrante_generado'       => $sobrGen,
            'sobrante_aplicado_monto' => 0,
            'estado'                  => 'calculado',
        ]);

        return $periodo->fresh();
    }

    /**
     * Aprueba el período y registra el sobrante generado.
     */
    public function aprobarPeriodo(PropinaPeriodo $periodo, int $userId): PropinaPeriodo
    {
        DB::connection('rrhh')->transaction(function () use ($periodo, $userId) {
            // Registrar sobrante si hay
            if ($periodo->sobrante_generado > 0) {
                PropinaSobrante::create([
                    'sucursal_id'       => $periodo->sucursal_id,
                    'periodo_origen_id' => $periodo->id,
                    'monto_original'    => $periodo->sobrante_generado,
                    'monto_distribuido' => 0,
                    'monto_pendiente'   => $periodo->sobrante_generado,
                    'estado'            => 'pendiente',
                ]);
            }

            $periodo->update([
                'estado'      => 'aprobado',
                'aprobado_por' => $userId,
                'aprobado_en'  => now(),
            ]);
        });

        return $periodo->fresh();
    }

    /**
     * Integra las propinas aprobadas a una planilla existente.
     * Agrega el monto de propina en planilla_lineas de cada empleado.
     */
    public function integrarAPlanilla(PropinaPeriodo $periodo, int $planillaId): int
    {
        $detalles = $periodo->detalles()->where('incluido', true)->get();
        $integrados = 0;

        DB::connection('rrhh')->transaction(function () use ($detalles, $planillaId, $periodo, &$integrados) {
            foreach ($detalles as $det) {
                $linea = DB::connection('rrhh')
                    ->table('planilla_lineas')
                    ->where('planilla_id', $planillaId)
                    ->where('empleado_id', $det->empleado_id)
                    ->first();

                if ($linea) {
                    DB::connection('rrhh')->table('planilla_lineas')
                        ->where('id', $linea->id)
                        ->update([
                            'propina'            => (float) $det->total_propina,
                            'propina_detalle_id' => $det->id,
                            'updated_at'         => now(),
                        ]);
                    $integrados++;
                }
            }

            $periodo->update([
                'estado'     => 'integrado',
                'planilla_id' => $planillaId,
            ]);
        });

        return $integrados;
    }
}
