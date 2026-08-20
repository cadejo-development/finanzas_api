<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\HorasExtrasSolicitud;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class HorasExtrasController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    // ── Listar ────────────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/horas-extras
     * - rrhh_admin / analistas : todas
     * - jefatura / gerencia_ops: las de sus subordinados + las propias
     * - empleado               : solo las propias
     */
    public function index(Request $request): JsonResponse
    {
        $miEmpleadoId = $this->empleadoIdPropio();
        $subordinadosIds = $this->getSubordinadosIds();
        $visiblesIds = array_unique(array_merge(
            $miEmpleadoId ? [$miEmpleadoId] : [],
            $subordinadosIds
        ));

        $query = HorasExtrasSolicitud::orderByDesc('created_at');

        if (!empty($visiblesIds)) {
            $query->whereIn('empleado_id', $visiblesIds);
        }

        if ($estado = $request->input('estado')) {
            $query->where('estado', $estado);
        }

        $solicitudes = $query->get();
        $data = $this->enrich($solicitudes->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * GET /api/rrhh/horas-extras/pendientes
     * Solicitudes pendientes de la aprobación del usuario actual.
     */
    public function pendientes(): JsonResponse
    {
        $miEmpleadoId = $this->empleadoIdPropio();
        if (!$miEmpleadoId) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $solicitudes = HorasExtrasSolicitud::where(function ($q) use ($miEmpleadoId) {
            $q->where(function ($q2) use ($miEmpleadoId) {
                $q2->where('estado', 'pendiente_n1')
                   ->where('n1_empleado_id', $miEmpleadoId);
            })->orWhere(function ($q2) use ($miEmpleadoId) {
                $q2->where('estado', 'pendiente_n2')
                   ->where('n2_empleado_id', $miEmpleadoId);
            });
        })->orderByDesc('created_at')->get();

        $data = $this->enrich($solicitudes->toArray());

        return response()->json(['success' => true, 'data' => $data, 'total' => count($data)]);
    }

    /** GET /api/rrhh/horas-extras/{id} */
    public function show(int $id): JsonResponse
    {
        $solicitud = HorasExtrasSolicitud::findOrFail($id);
        $this->autorizarLectura($solicitud);
        $data = $this->enrich([$solicitud->toArray()]);
        return response()->json(['success' => true, 'data' => $data[0]]);
    }

    // ── Crear ─────────────────────────────────────────────────────────────────

    /** POST /api/rrhh/horas-extras */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $v = $request->validate([
                'empleado_id'  => 'required|integer',
                'fecha_inicio' => 'required|date',
                'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
                'horas'        => 'required|numeric|min:0.5|max:999',
                'descripcion'  => 'nullable|string|max:1000',
            ]);

            $empleadoId     = (int) $v['empleado_id'];
            $solicitadoPorId = $this->empleadoIdPropio();

            if (!$solicitadoPorId) {
                return response()->json(['success' => false, 'message' => 'No tienes empleado vinculado.'], 403);
            }

            if (!$this->puedeGestionar($empleadoId)) {
                return response()->json(['success' => false, 'message' => 'No puedes gestionar a este empleado.'], 403);
            }

            // ── Resolver cadena de aprobación ────────────────────────────────
            [$n1Id, $n2Id, $requiereN2, $deptId] = $this->resolverCadena($empleadoId);

            // ── Determinar N1 skip ────────────────────────────────────────────
            // Skip N1 si: (a) el que envía es N1, (b) el empleado ES el N1 (jefe)
            $skipN1 = $n1Id && (
                $solicitadoPorId === $n1Id ||
                $empleadoId      === $n1Id
            );

            if ($skipN1) {
                $estado = ($requiereN2 && $n2Id) ? 'pendiente_n2' : 'aprobada';
            } else {
                $estado = 'pendiente_n1';
            }

            // ── Quincena trabajada (basada en fecha_inicio) ───────────────────
            $fechaInicio  = Carbon::parse($v['fecha_inicio']);
            $qTrabajoNum  = $fechaInicio->day <= 15 ? 1 : 2;

            // ── Quincena de pago: siempre vencida (Q1→Q2 mismo mes, Q2→Q1 mes siguiente) ──
            [$pagoAnio, $pagoMes, $pagoNum] = $estado === 'aprobada'
                ? $this->calcularQuincenapago($fechaInicio->year, $fechaInicio->month, $qTrabajoNum)
                : [null, null, null];

            $solicitud = HorasExtrasSolicitud::create([
                'empleado_id'                => $empleadoId,
                'solicitado_por_empleado_id' => $solicitadoPorId,
                'departamento_id'            => $deptId,
                'fecha_inicio'               => $v['fecha_inicio'],
                'fecha_fin'                  => $v['fecha_fin'],
                'horas'                      => $v['horas'],
                'descripcion'                => $v['descripcion'] ?? null,
                'estado'                     => $estado,
                'n1_empleado_id'             => $skipN1 ? null : $n1Id,
                'n1_aprobado_por_id'         => $skipN1 ? $solicitadoPorId : null,
                'n1_fecha'                   => $skipN1 ? now() : null,
                'n2_requerido'               => $requiereN2,
                'n2_empleado_id'             => ($requiereN2 && $n2Id) ? $n2Id : null,
                'quincena_trabajo_anio'      => $fechaInicio->year,
                'quincena_trabajo_mes'       => $fechaInicio->month,
                'quincena_trabajo_num'       => $qTrabajoNum,
                'quincena_pago_anio'         => $pagoAnio,
                'quincena_pago_mes'          => $pagoMes,
                'quincena_pago_num'          => $pagoNum,
                'aud_usuario'                => Auth::user()->email,
            ]);

            $data = $this->enrich([$solicitud->toArray()]);
            return response()->json(['success' => true, 'data' => $data[0]], 201);
        });
    }

    // ── Aprobar / rechazar ────────────────────────────────────────────────────

    /** POST /api/rrhh/horas-extras/{id}/aprobar */
    public function aprobar(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $v = $request->validate(['observaciones' => 'nullable|string|max:1000']);

            $solicitud    = HorasExtrasSolicitud::findOrFail($id);
            $miEmpleadoId = $this->empleadoIdPropio();

            if ($solicitud->estado === 'pendiente_n1') {
                if ((int) $solicitud->n1_empleado_id !== $miEmpleadoId) {
                    return response()->json(['success' => false, 'message' => 'No eres el aprobador N1.'], 403);
                }

                // Después de N1: ¿se necesita N2?
                $siguienteEstado = ($solicitud->n2_requerido && $solicitud->n2_empleado_id)
                    ? 'pendiente_n2'
                    : 'aprobada';

                $solicitud->update([
                    'estado'             => $siguienteEstado,
                    'n1_aprobado_por_id' => $miEmpleadoId,
                    'n1_fecha'           => now(),
                    'n1_observaciones'   => $v['observaciones'] ?? null,
                ] + ($siguienteEstado === 'aprobada' ? $this->pagoPayload($solicitud) : []));

            } elseif ($solicitud->estado === 'pendiente_n2') {
                if ((int) $solicitud->n2_empleado_id !== $miEmpleadoId) {
                    return response()->json(['success' => false, 'message' => 'No eres el aprobador N2.'], 403);
                }

                $solicitud->update([
                    'estado'             => 'aprobada',
                    'n2_aprobado_por_id' => $miEmpleadoId,
                    'n2_fecha'           => now(),
                    'n2_observaciones'   => $v['observaciones'] ?? null,
                ] + $this->pagoPayload($solicitud));

            } else {
                return response()->json(['success' => false, 'message' => "No se puede aprobar en estado '{$solicitud->estado}'."], 422);
            }

            $data = $this->enrich([$solicitud->fresh()->toArray()]);
            return response()->json(['success' => true, 'data' => $data[0]]);
        });
    }

    /** POST /api/rrhh/horas-extras/{id}/rechazar */
    public function rechazar(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $v = $request->validate(['observaciones' => 'nullable|string|max:1000']);

            $solicitud    = HorasExtrasSolicitud::findOrFail($id);
            $miEmpleadoId = $this->empleadoIdPropio();

            $esN1 = $solicitud->estado === 'pendiente_n1' && (int) $solicitud->n1_empleado_id === $miEmpleadoId;
            $esN2 = $solicitud->estado === 'pendiente_n2' && (int) $solicitud->n2_empleado_id === $miEmpleadoId;

            if (!$esN1 && !$esN2) {
                return response()->json(['success' => false, 'message' => 'No tienes permiso para rechazar esta solicitud.'], 403);
            }

            $solicitud->update([
                'estado'               => 'rechazada',
                'rechazo_observaciones'=> $v['observaciones'] ?? null,
            ]);

            return response()->json(['success' => true, 'message' => 'Solicitud rechazada.']);
        });
    }

    // ── Config ────────────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/horas-extras/config
     * Lista departamentos con su configuración de aprobación.
     */
    public function configIndex(): JsonResponse
    {
        $depts = DB::connection('pgsql')
            ->table('departamentos as d')
            ->leftJoin('horas_extras_config as c', 'c.departamento_id', '=', 'd.id')
            ->leftJoin('empleados as e', 'e.id', '=', 'd.jefe_empleado_id')
            ->where('d.activo', true)
            ->select(
                'd.id', 'd.codigo', 'd.nombre', 'd.parent_id',
                DB::connection('pgsql')->raw("CONCAT(e.nombres,' ',e.apellidos) as jefe_nombre"),
                'd.jefe_empleado_id',
                'c.id as config_id',
                DB::connection('pgsql')->raw('COALESCE(c.requiere_n2, false) as requiere_n2'),
            )
            ->orderBy('d.nombre')
            ->get();

        return response()->json(['success' => true, 'data' => $depts]);
    }

    /**
     * PUT /api/rrhh/horas-extras/config/{deptId}
     */
    public function configUpdate(Request $request, int $deptId): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $deptId) {
            $v = $request->validate(['requiere_n2' => 'required|boolean']);

            DB::connection('pgsql')->table('horas_extras_config')->upsert(
                [
                    'departamento_id' => $deptId,
                    'requiere_n2'     => $v['requiere_n2'],
                    'activo'          => true,
                    'aud_usuario'     => Auth::user()->email,
                    'created_at'      => now(),
                    'updated_at'      => now(),
                ],
                ['departamento_id'],
                ['requiere_n2', 'aud_usuario', 'updated_at']
            );

            return response()->json(['success' => true]);
        });
    }

    // ── Privados ──────────────────────────────────────────────────────────────

    private function empleadoIdPropio(): ?int
    {
        $id = DB::connection('pgsql')
            ->table('empleados')
            ->where('user_id', Auth::id())
            ->value('id');
        return $id ? (int) $id : null;
    }

    /**
     * Resuelve la cadena de aprobación para un empleado.
     * Retorna [n1_id, n2_id, requiere_n2, dept_id].
     */
    private function resolverCadena(int $empleadoId): array
    {
        // Buscar departamento del empleado
        $row = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('departamentos as d', 'd.id', '=', 'e.departamento_id')
            ->where('e.id', $empleadoId)
            ->select('d.id as dept_id', 'd.jefe_empleado_id', 'd.parent_id')
            ->first();

        // Si no tiene departamento_id, buscar dept donde es jefe
        if (!$row || !$row->dept_id) {
            $row = DB::connection('pgsql')
                ->table('departamentos as d')
                ->where('d.jefe_empleado_id', $empleadoId)
                ->where('d.activo', true)
                ->select('d.id as dept_id', 'd.jefe_empleado_id', 'd.parent_id')
                ->first();
        }

        if (!$row) return [null, null, false, null];

        $deptId    = (int) $row->dept_id;
        $n1Id      = $row->jefe_empleado_id ? (int) $row->jefe_empleado_id : null;

        // N2 = jefe del departamento padre
        $n2Id = null;
        if ($row->parent_id) {
            $n2Id = DB::connection('pgsql')
                ->table('departamentos')
                ->where('id', $row->parent_id)
                ->where('activo', true)
                ->value('jefe_empleado_id');
            $n2Id = $n2Id ? (int) $n2Id : null;
        }

        // ¿Se requiere N2?
        $config = DB::connection('pgsql')
            ->table('horas_extras_config')
            ->where('departamento_id', $deptId)
            ->where('activo', true)
            ->first();

        $requiereN2 = (bool) ($config?->requiere_n2 ?? false);

        return [$n1Id, $n2Id, $requiereN2, $deptId];
    }

    /**
     * Quincena de pago = siguiente a la de trabajo, pero nunca antes de la quincena actual.
     * Si la aprobación es tardía (la quincena de pago ya venció), se mueve a la siguiente
     * quincena desde hoy.
     *
     * Ejemplos:
     *   Trabajo Q1 Ago, aprobado 10-ago  → pago Q2 Ago (Q2 Ago >= Q1 Ago ✓)
     *   Trabajo Q1 Ago, aprobado 03-sep  → pago Q2 Sep (Q2 Ago < Q1 Sep → siguiente de hoy)
     *   Trabajo Q2 Ago, aprobado 20-ago  → pago Q1 Sep (Q1 Sep >= Q2 Ago ✓)
     */
    private function calcularQuincenapago(int $trabajoAnio, int $trabajoMes, int $trabajoNum): array
    {
        // Quincena siguiente a la de trabajo
        if ($trabajoNum === 1) {
            [$pA, $pM, $pQ] = [$trabajoAnio, $trabajoMes, 2];
        } else {
            $sig            = Carbon::create($trabajoAnio, $trabajoMes, 1)->addMonth();
            [$pA, $pM, $pQ] = [$sig->year, $sig->month, 1];
        }

        // Quincena actual según hoy
        $hoy = now();
        [$hA, $hM, $hQ] = [$hoy->year, $hoy->month, $hoy->day <= 15 ? 1 : 2];

        // Si la quincena de pago calculada ya venció → usar siguiente a la actual
        $pagoPasado = $pA < $hA
            || ($pA === $hA && $pM < $hM)
            || ($pA === $hA && $pM === $hM && $pQ < $hQ);

        if ($pagoPasado) {
            if ($hQ === 1) {
                return [$hA, $hM, 2];
            }
            $sig = Carbon::create($hA, $hM, 1)->addMonth();
            return [$sig->year, $sig->month, 1];
        }

        return [$pA, $pM, $pQ];
    }

    private function pagoPayload(HorasExtrasSolicitud $solicitud): array
    {
        [$anio, $mes, $num] = $this->calcularQuincenapago(
            (int) $solicitud->quincena_trabajo_anio,
            (int) $solicitud->quincena_trabajo_mes,
            (int) $solicitud->quincena_trabajo_num,
        );
        return [
            'quincena_pago_anio' => $anio,
            'quincena_pago_mes'  => $mes,
            'quincena_pago_num'  => $num,
        ];
    }

    /**
     * Verifica que el usuario pueda leer esta solicitud.
     */
    private function autorizarLectura(HorasExtrasSolicitud $s): void
    {
        if ($this->esAdminRrhh() || $this->esAnalistaRrhh()) return;

        $miId = $this->empleadoIdPropio();
        if (!$miId) abort(403);

        $esDelEmpleado    = (int) $s->empleado_id === $miId;
        $esSolicitante    = (int) $s->solicitado_por_empleado_id === $miId;
        $esAprobadorN1    = (int) $s->n1_empleado_id === $miId;
        $esAprobadorN2    = (int) $s->n2_empleado_id === $miId;

        if (!$esDelEmpleado && !$esSolicitante && !$esAprobadorN1 && !$esAprobadorN2) {
            abort(403);
        }
    }

    /**
     * Enriquece solicitudes con nombres de empleados desde pgsql.
     */
    private function enrich(array $records): array
    {
        if (empty($records)) return $records;

        $empleadoIds = collect($records)
            ->flatMap(fn($r) => [
                $r['empleado_id'] ?? null,
                $r['solicitado_por_empleado_id'] ?? null,
                $r['n1_empleado_id'] ?? null,
                $r['n1_aprobado_por_id'] ?? null,
                $r['n2_empleado_id'] ?? null,
                $r['n2_aprobado_por_id'] ?? null,
            ])
            ->unique()
            ->filter()
            ->values()
            ->all();

        $empleados = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c', 'c.id', '=', 'e.cargo_id')
            ->leftJoin('departamentos as d', 'd.id', '=', 'e.departamento_id')
            ->whereIn('e.id', $empleadoIds)
            ->select('e.id', 'e.nombres', 'e.apellidos', 'c.nombre as cargo', 'd.nombre as departamento')
            ->get()
            ->keyBy('id');

        $nombre = fn(?int $id) => $id && isset($empleados[$id])
            ? trim($empleados[$id]->nombres . ' ' . $empleados[$id]->apellidos)
            : null;

        return collect($records)->map(function ($r) use ($empleados, $nombre) {
            $r = is_array($r) ? $r : (array) $r;
            $emp = $empleados[$r['empleado_id'] ?? null] ?? null;
            $r['empleado_nombre']      = $nombre($r['empleado_id'] ?? null);
            $r['empleado_cargo']       = $emp?->cargo;
            $r['empleado_departamento']= $emp?->departamento;
            $r['solicitado_por_nombre']= $nombre($r['solicitado_por_empleado_id'] ?? null);
            $r['n1_nombre']            = $nombre($r['n1_empleado_id'] ?? null);
            $r['n1_aprobado_por_nombre']= $nombre($r['n1_aprobado_por_id'] ?? null);
            $r['n2_nombre']            = $nombre($r['n2_empleado_id'] ?? null);
            $r['n2_aprobado_por_nombre']= $nombre($r['n2_aprobado_por_id'] ?? null);
            return $r;
        })->all();
    }
}
