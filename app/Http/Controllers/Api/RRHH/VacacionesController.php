<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\SaldoVacaciones;
use App\Models\RRHH\Vacacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class VacacionesController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;
    /**
     * GET /api/rrhh/vacaciones
     */
    public function index(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $propioId = null;
        try {
            $propioId = $this->getJefeEmpleado()->id;
            $subordinadosIds = array_values(array_unique(array_merge($subordinadosIds, [$propioId])));
        } catch (\Throwable) {}

        $vacaciones = Vacacion::where(function ($q) use ($subordinadosIds, $propioId) {
                $q->whereIn('empleado_id', $subordinadosIds);
                if ($propioId) {
                    $q->orWhere('jefe_id', $propioId);
                }
            })
            ->orderByDesc('id')
            ->get();

        $data = $this->enrichWithEmpleadoData($vacaciones->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/vacaciones
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'empleado_id'  => 'required|integer',
            'fecha_inicio' => 'required|date',
            'fecha_fin'    => 'required|date|after_or_equal:fecha_inicio',
            'dias'         => 'required|numeric|min:0.5',
            'observaciones'=> 'nullable|string|max:500',
        ]);

        $jefe = $this->getJefeEmpleado();

        if (!$this->puedeGestionar($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        // ── Validaciones de negocio ───────────────────────────────────────────
        $diasSolicitados  = (float) $validated['dias'];
        $esDeRestaurante  = $this->esEmpleadoDeRestaurante($validated['empleado_id']);
        $esAdmin          = $this->esAdminRrhh();
        $esEmpleado       = $this->esEmpleado();

        // Anticipación mínima de 30 días — solo para colaboradores con rol empleado
        if ($esEmpleado) {
            $diasAnticipacion = now()->diffInDays(\Carbon\Carbon::parse($validated['fecha_inicio']), false);
            if ($diasAnticipacion < 30) {
                return response()->json([
                    'success' => false,
                    'message' => "Las vacaciones deben solicitarse con al menos 30 días de anticipación. La fecha elegida está a {$diasAnticipacion} día(s) de hoy.",
                ], 422);
            }
        }

        // Personal operativo (restaurante): solo un período continuo al año
        if ($esDeRestaurante) {
            $yaExiste = Vacacion::where('empleado_id', $validated['empleado_id'])
                ->whereYear('fecha_inicio', now()->year)
                ->whereIn('estado', ['pendiente', 'aprobado'])
                ->exists();

            if ($yaExiste && !$esAdmin) {
                return response()->json([
                    'success' => false,
                    'message' => 'El personal operativo debe tomar las vacaciones en un único período continuo. Ya existe una solicitud de vacaciones registrada para este año.',
                ], 422);
            }
        }

        // Validar traslape de fechas con solicitudes activas
        $traslape = Vacacion::where('empleado_id', $validated['empleado_id'])
            ->whereIn('estado', ['pendiente', 'aprobado'])
            ->where('fecha_inicio', '<=', $validated['fecha_fin'])
            ->where('fecha_fin', '>=', $validated['fecha_inicio'])
            ->exists();

        if ($traslape) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe una solicitud de vacaciones que se traslapa con el período seleccionado.',
            ], 422);
        }

        // Mínimo 5 días por solicitud
        if ($diasSolicitados < 5) {
            return response()->json([
                'success' => false,
                'message' => 'El mínimo de días de vacaciones por solicitud es 5.',
            ], 422);
        }

        // Calcular días disponibles reales (incluye acumulación del año anterior, máx 30)
        $diasBase = $this->calcularDiasDisponibles($validated['empleado_id'], now()->year);

        $diasYaSolicitados = Vacacion::where('empleado_id', $validated['empleado_id'])
            ->whereYear('fecha_inicio', now()->year)
            ->whereIn('estado', ['pendiente', 'aprobado'])
            ->sum('dias');

        $disponibles = max($diasBase - (float) $diasYaSolicitados, 0);

        if ($diasSolicitados > $disponibles) {
            return response()->json([
                'success' => false,
                'message' => "Solo quedan {$disponibles} día(s) disponibles (de {$diasBase} en total este año). Ya se han solicitado {$diasYaSolicitados} días.",
            ], 422);
        }

        $aprobadorId  = $this->getAprobadorPara($validated['empleado_id']);
        $estadoInicial = $this->estadoParaEmpleado($validated['empleado_id'], $aprobadorId);
        $vacacion = Vacacion::create(array_merge($validated, [
            'jefe_id'     => $aprobadorId ?? $jefe->id,
            'estado'      => $estadoInicial,
            'aud_usuario' => Auth::user()->email,
            'creado_por'  => $this->creadoPor(),
        ]));

        // Si se crea directamente como aprobado (jefe aprueba para subordinado), descontar saldo
        if ($estadoInicial === 'aprobado') {
            $this->descontarSaldo($validated['empleado_id'], (float) $validated['dias']);
        }

        // Notify supervisor when employee submits own request (or jefe submits for themselves)
        if ($this->debeNotificar($validated['empleado_id'])) {
            $detalles = array_filter([
                'Fecha inicio'  => $validated['fecha_inicio'],
                'Fecha fin'     => $validated['fecha_fin'],
                'Días'          => $validated['dias'] . ' día(s)',
                'Observaciones' => $validated['observaciones'] ?? null,
            ]);
            $this->notificarSolicitud($validated['empleado_id'], 'Vacaciones', $detalles, 'vacaciones', $vacacion->id, 'vacacion');
        }

        $arr = $this->enrichWithEmpleadoData([$vacacion->toArray()]);
        return response()->json(['success' => true, 'data' => $arr[0]], 201);
    }

    /**
     * GET /api/rrhh/vacaciones/{id}
     */
    public function show(int $id): JsonResponse
    {
        $vacacion = Vacacion::findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$vacacion->toArray()]);

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * PUT /api/rrhh/vacaciones/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $vacacion = Vacacion::findOrFail($id);

        $validated = $request->validate([
            'fecha_inicio'  => 'sometimes|date',
            'fecha_fin'     => 'sometimes|date|after_or_equal:fecha_inicio',
            'dias'          => 'sometimes|numeric|min:0.5',
            'estado'        => 'sometimes|in:pendiente,aprobado,rechazado',
            'observaciones' => 'nullable|string|max:500',
        ]);

        $vacacion->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));

        // Si se aprueba, descontar del saldo
        if (($validated['estado'] ?? null) === 'aprobado') {
            $this->descontarSaldo($vacacion->empleado_id, $vacacion->dias);
        }

        return response()->json(['success' => true, 'data' => $vacacion]);
    }

    /**
     * DELETE /api/rrhh/vacaciones/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        Vacacion::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Vacación eliminada.']);
    }

    /**
     * Saldo de vacaciones del equipo para el año actual.
     * GET /api/rrhh/vacaciones/saldos
     */
    public function saldos(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        // Incluir el propio jefe (igual que index()) para que su saldo aparezca correctamente
        try {
            $propioId        = $this->getJefeEmpleado()->id;
            $subordinadosIds = array_values(array_unique(array_merge($subordinadosIds, [$propioId])));
        } catch (\Throwable) {}

        $anio = now()->year;

        $saldosAnio = SaldoVacaciones::whereIn('empleado_id', $subordinadosIds)
            ->where('anio', $anio)
            ->get()
            ->keyBy('empleado_id');

        // Año anterior para calcular carry-over en batch
        $saldosAnterior = SaldoVacaciones::whereIn('empleado_id', $subordinadosIds)
            ->where('anio', $anio - 1)
            ->get()
            ->keyBy('empleado_id');

        $data = collect($subordinadosIds)->map(function ($empId) use ($saldosAnio, $saldosAnterior, $anio) {
            $saldo = $saldosAnio[$empId] ?? null;

            if ($saldo) {
                $disponibles = (float) $saldo->dias_disponibles;
                $usados      = (float) $saldo->dias_usados;
            } else {
                // Sin registro: calcular con carry-over del año anterior
                $anterior    = $saldosAnterior[$empId] ?? null;
                $noUsados    = $anterior ? max(0, (float)$anterior->dias_disponibles - (float)$anterior->dias_usados) : 0;
                $disponibles = min(15 + $noUsados, 30);
                $usados      = 0;
            }

            $restantes = max(0, $disponibles - $usados);

            return [
                'empleado_id'      => $empId,
                'anio'             => $anio,
                'dias_disponibles' => $disponibles,
                'dias_usados'      => $usados,
                'dias_acumulados'  => $restantes,   // días restantes no usados (UI los muestra como "Acumulados")
                'dias_totales'     => $disponibles,
            ];
        });

        $result = $this->enrichWithEmpleadoData($data->all());

        return response()->json(['success' => true, 'data' => $result]);
    }

    private function descontarSaldo(int $empleadoId, float $dias): void
    {
        $anio  = now()->year;
        $disp  = $this->calcularDiasDisponibles($empleadoId, $anio);
        $saldo = SaldoVacaciones::firstOrCreate(
            ['empleado_id' => $empleadoId, 'anio' => $anio],
            ['dias_disponibles' => $disp, 'dias_usados' => 0, 'dias_acumulados' => 0]
        );
        $saldo->increment('dias_usados', $dias);
    }

    /**
     * Días disponibles para un empleado en un año dado.
     * Incluye carry-over del año anterior (máximo 30 días total = 2 años).
     */
    private function calcularDiasDisponibles(int $empleadoId, int $anio): float
    {
        $saldoAnio = SaldoVacaciones::where('empleado_id', $empleadoId)
            ->where('anio', $anio)
            ->first();

        // Si ya existe registro para este año, usar ese valor
        if ($saldoAnio) {
            return (float) $saldoAnio->dias_disponibles;
        }

        // Calcular carry-over desde año anterior
        $anterior = SaldoVacaciones::where('empleado_id', $empleadoId)
            ->where('anio', $anio - 1)
            ->first();

        $noUsados = $anterior
            ? max(0, (float)$anterior->dias_disponibles - (float)$anterior->dias_usados)
            : 0;

        return min(15 + $noUsados, 30);
    }
}
