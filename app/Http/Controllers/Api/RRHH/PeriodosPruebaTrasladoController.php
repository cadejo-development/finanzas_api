<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\PeriodoPruebaTraslado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PeriodosPruebaTrasladoController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    /**
     * GET /api/rrhh/periodos-prueba
     */
    public function index(): JsonResponse
    {
        $query = PeriodoPruebaTraslado::orderByDesc('id');

        if (!$this->esAdminRrhh() && !$this->esAnalistaRrhh() && !$this->esGerenciaOps()) {
            $subordinadosIds = $this->getSubordinadosIds();
            $misSucursales   = $this->getSucursalesGestionadas();
            $query->where(function ($q) use ($subordinadosIds, $misSucursales) {
                $q->whereIn('empleado_id', $subordinadosIds);
                if (!empty($misSucursales)) {
                    $q->orWhereIn('sucursal_prueba_id', $misSucursales);
                }
            });
        }

        $periodos = $query->get();
        $data = $this->enrichWithEmpleadoData($periodos->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/periodos-prueba
     */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $jefe = $this->getJefeEmpleado();

            $validated = $request->validate([
                'empleado_id'       => 'required|integer',
                'cargo_prueba_id'   => 'required|integer',
                'sucursal_prueba_id'=> 'required|integer',
                'fecha_inicio'      => 'required|date',
                'motivo'            => 'nullable|string|max:500',
            ]);

            if (!$this->puedeGestionar($validated['empleado_id'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'El empleado no pertenece a tu equipo.',
                ], 403);
            }

            // Datos actuales del empleado (origen)
            $empData = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('cargos as c',    'e.cargo_id',    '=', 'c.id')
                ->leftJoin('sucursales as s', 'e.sucursal_id', '=', 's.id')
                ->where('e.id', $validated['empleado_id'])
                ->select('e.sucursal_id', 's.nombre as sucursal_nombre', 'e.cargo_id', 'c.nombre as cargo_nombre')
                ->first();

            // Datos del cargo y sucursal de la prueba
            $cargoPrueba = DB::connection('pgsql')
                ->table('cargos')
                ->where('id', $validated['cargo_prueba_id'])
                ->value('nombre');

            $sucursalPrueba = DB::connection('pgsql')
                ->table('sucursales')
                ->where('id', $validated['sucursal_prueba_id'])
                ->value('nombre');

            $fechaInicio = $validated['fecha_inicio'];
            $fechaFin    = \Carbon\Carbon::parse($fechaInicio)->addMonth()->toDateString();

            $periodo = PeriodoPruebaTraslado::create([
                'empleado_id'           => $validated['empleado_id'],
                'solicitado_por_id'     => $jefe->id,
                'sucursal_origen_id'    => $empData?->sucursal_id,
                'sucursal_origen_nombre'=> $empData?->sucursal_nombre,
                'cargo_origen_id'       => $empData?->cargo_id,
                'cargo_origen_nombre'   => $empData?->cargo_nombre,
                'sucursal_prueba_id'    => $validated['sucursal_prueba_id'],
                'sucursal_prueba_nombre'=> $sucursalPrueba,
                'cargo_prueba_id'       => $validated['cargo_prueba_id'],
                'cargo_prueba_nombre'   => $cargoPrueba,
                'fecha_inicio'          => $fechaInicio,
                'fecha_fin'             => $fechaFin,
                'estado'                => 'activo',
                'aud_usuario'           => Auth::user()->email,
            ]);

            $arr = $this->enrichWithEmpleadoData([$periodo->toArray()]);
            $empleadoNombre = $arr[0]['empleado_nombre'] ?? "Empleado #{$validated['empleado_id']}";

            $esMismaSucursal = (int)$validated['sucursal_prueba_id'] === (int)$empData?->sucursal_id;

            $detallesNotif = [
                'Sucursal actual'  => $empData?->sucursal_nombre ?? '—',
                'Cargo actual'     => $empData?->cargo_nombre    ?? '—',
                'Cargo en prueba'  => $cargoPrueba               ?? '—',
                'Sucursal prueba'  => $sucursalPrueba             ?? '—',
                'Tipo'             => $esMismaSucursal ? 'Prueba en misma sucursal' : 'Prueba en otra sucursal',
                'Fecha inicio'     => \Carbon\Carbon::parse($fechaInicio)->format('d/m/Y'),
                'Fecha fin'        => \Carbon\Carbon::parse($fechaFin)->format('d/m/Y'),
                'Motivo'           => $validated['motivo'] ?? '—',
            ];

            $this->notificarAdminsRrhh(
                tipo:           'Período de Prueba',
                empleadoNombre: $empleadoNombre,
                detalles:       $detallesNotif,
                rutaFrontend:   'traslados',
            );

            return response()->json(['success' => true, 'data' => $arr[0]], 201);
        });
    }

    /**
     * PATCH /api/rrhh/periodos-prueba/{id}/decision
     * Aprobar o rechazar el período de prueba.
     */
    public function decision(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $periodo = PeriodoPruebaTraslado::findOrFail($id);

            if ($periodo->estado !== 'activo') {
                return response()->json([
                    'success' => false,
                    'message' => 'Este período de prueba ya fue procesado.',
                ], 422);
            }

            $validated = $request->validate([
                'decision'      => 'required|in:aprobado,rechazado',
                'notas'         => 'nullable|string|max:1000',
            ]);

            // Verificar plazas disponibles si se aprueba (advertencia, no bloqueo)
            $advertenciaPlazas = null;
            if ($validated['decision'] === 'aprobado') {
                $plazasDisponibles = DB::connection('pgsql')
                    ->table('plazas as p')
                    ->join('departamentos as d', 'd.id', '=', 'p.departamento_id')
                    ->leftJoin('empleados as e', fn($j) =>
                        $j->on('e.plaza_id', '=', 'p.id')->where('e.activo', true))
                    ->where('p.cargo_id', $periodo->cargo_prueba_id)
                    ->where('d.sucursal_id', $periodo->sucursal_prueba_id)
                    ->where('p.activo', true)
                    ->whereNull('e.id')
                    ->count();

                if ($plazasDisponibles === 0) {
                    $advertenciaPlazas = "No hay plazas vacantes para el cargo \"{$periodo->cargo_prueba_nombre}\" en {$periodo->sucursal_prueba_nombre}. El empleado fue aprobado igualmente.";
                }

                // Aplicar: actualizar cargo (y sucursal si es diferente) del empleado
                $updates = ['cargo_id' => $periodo->cargo_prueba_id, 'updated_at' => now()];
                if ($periodo->sucursal_prueba_id && $periodo->sucursal_prueba_id !== $periodo->sucursal_origen_id) {
                    $updates['sucursal_id'] = $periodo->sucursal_prueba_id;
                }
                DB::connection('pgsql')
                    ->table('empleados')
                    ->where('id', $periodo->empleado_id)
                    ->update($updates);
            }

            $periodo->update([
                'estado'        => $validated['decision'],
                'decision_notas'=> $validated['notas'] ?? null,
                'decision_en'   => now(),
                'decision_por'  => Auth::user()->email,
                'aud_usuario'   => Auth::user()->email,
            ]);

            $arr = $this->enrichWithEmpleadoData([$periodo->fresh()->toArray()]);
            $empleadoNombre = $arr[0]['empleado_nombre'] ?? "Empleado #{$periodo->empleado_id}";

            $detallesNotif = [
                'Cargo en prueba'   => $periodo->cargo_prueba_nombre  ?? '—',
                'Sucursal prueba'   => $periodo->sucursal_prueba_nombre ?? '—',
                'Decisión'          => $validated['decision'] === 'aprobado' ? 'Aprobado' : 'No aprobado',
                'Notas'             => $validated['notas'] ?? '—',
                'Decidido por'      => Auth::user()->email,
            ];

            $this->notificarAdminsRrhh(
                tipo:           'Decisión Período de Prueba',
                empleadoNombre: $empleadoNombre,
                detalles:       $detallesNotif,
                rutaFrontend:   'traslados',
            );

            $response = ['success' => true, 'data' => $arr[0]];
            if ($advertenciaPlazas) {
                $response['advertencia'] = $advertenciaPlazas;
            }

            return response()->json($response);
        });
    }

    /**
     * PATCH /api/rrhh/periodos-prueba/{id}/cancelar
     */
    public function cancelar(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $periodo = PeriodoPruebaTraslado::findOrFail($id);

            if ($periodo->estado !== 'activo') {
                return response()->json([
                    'success' => false,
                    'message' => 'Solo se pueden cancelar períodos activos.',
                ], 422);
            }

            $periodo->update([
                'estado'      => 'cancelado',
                'decision_en' => now(),
                'decision_por'=> Auth::user()->email,
                'aud_usuario' => Auth::user()->email,
            ]);

            $arr = $this->enrichWithEmpleadoData([$periodo->fresh()->toArray()]);
            return response()->json(['success' => true, 'data' => $arr[0]]);
        });
    }

    /**
     * GET /api/rrhh/periodos-prueba/cargos-sucursal?sucursal_id=X
     * Retorna los cargos que tienen plazas activas en la sucursal indicada.
     */
    public function cargosPorSucursal(Request $request): JsonResponse
    {
        $sucursalId = (int) $request->query('sucursal_id');

        if (!$sucursalId) {
            return response()->json(['success' => false, 'message' => 'sucursal_id requerido'], 422);
        }

        $cargos = DB::connection('pgsql')
            ->table('plazas as p')
            ->join('departamentos as d', 'd.id', '=', 'p.departamento_id')
            ->join('cargos as c', 'c.id', '=', 'p.cargo_id')
            ->where('d.sucursal_id', $sucursalId)
            ->where('p.activo', true)
            ->select('c.id', 'c.nombre')
            ->distinct()
            ->orderBy('c.nombre')
            ->get();

        return response()->json(['success' => true, 'data' => $cargos]);
    }

    /**
     * Sucursales que gestiona el usuario (copiado de TrasladosController).
     */
    private function getSucursalesGestionadas(): array
    {
        $user = $this->getEffectiveUser();

        $jefeEmpleadoId = DB::connection('pgsql')
            ->table('empleados')
            ->where('user_id', $user->id)
            ->value('id');

        if (!$jefeEmpleadoId) {
            return $user->sucursal_id ? [(int) $user->sucursal_id] : [];
        }

        $deptSucursales = DB::connection('pgsql')
            ->table('departamentos')
            ->where('jefe_empleado_id', $jefeEmpleadoId)
            ->where('activo', true)
            ->whereNotNull('sucursal_id')
            ->pluck('sucursal_id')
            ->map(fn($id) => (int) $id)
            ->unique()
            ->all();

        if (!empty($deptSucursales)) return $deptSucursales;

        $jefaturas = DB::connection('pgsql')
            ->table('empleado_jefaturas')
            ->where('empleado_id', $jefeEmpleadoId)
            ->where('activo', true)
            ->whereNotNull('sucursal_id')
            ->pluck('sucursal_id')
            ->map(fn($id) => (int) $id)
            ->all();

        if (!empty($jefaturas)) return $jefaturas;

        return $user->sucursal_id ? [(int) $user->sucursal_id] : [];
    }
}
