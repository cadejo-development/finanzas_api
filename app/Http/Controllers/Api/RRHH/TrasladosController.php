<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Traslado;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class TrasladosController extends RRHHBaseController
{
    /**
     * GET /api/rrhh/traslados
     */
    public function index(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $traslados = Traslado::whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('id')
            ->get();

        $data = $this->enrichWithEmpleadoData($traslados->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/traslados
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'empleado_id'              => 'required|integer',
            'sucursal_destino_id'      => 'required|integer',
            'cargo_destino_id'         => 'nullable|integer',
            'departamento_destino_id'  => 'nullable|integer',
            'fecha_efectiva'           => 'required|date',
            'motivo'                   => 'nullable|string|max:500',
        ]);

        $jefe = $this->getJefeEmpleado();

        if (!$this->esSubordinado($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        // Capturar datos actuales del empleado (origen)
        $empData = DB::connection('pgsql')
            ->table('empleados as e')
            ->leftJoin('cargos as c',        'e.cargo_id',        '=', 'c.id')
            ->leftJoin('sucursales as s',     'e.sucursal_id',     '=', 's.id')
            ->leftJoin('departamentos as d',  'e.departamento_id', '=', 'd.id')
            ->where('e.id', $validated['empleado_id'])
            ->select(
                'e.sucursal_id', 's.nombre as sucursal_nombre',
                'e.cargo_id',    'c.nombre as cargo_nombre',
                'e.departamento_id', 'd.nombre as departamento_nombre'
            )
            ->first();

        // Nombres de destino
        $sucursalDestino = DB::connection('pgsql')
            ->table('sucursales')
            ->where('id', $validated['sucursal_destino_id'])
            ->value('nombre');

        $cargoDestino = null;
        if (!empty($validated['cargo_destino_id'])) {
            $cargoDestino = DB::connection('pgsql')
                ->table('cargos')
                ->where('id', $validated['cargo_destino_id'])
                ->value('nombre');
        }

        $deptDestino = null;
        if (!empty($validated['departamento_destino_id'])) {
            $deptDestino = DB::connection('pgsql')
                ->table('departamentos')
                ->where('id', $validated['departamento_destino_id'])
                ->value('nombre');
        }

        // rrhh_admin: aprobado directo. Jefatura: pendiente, espera aprobación del gerente destino.
        $estado = $this->esAdminRrhh() ? 'aprobado' : 'pendiente';

        // Si no se especificó departamento destino, auto-asignar el del restaurante destino
        $deptoDestinoId   = $validated['departamento_destino_id'] ?? null;
        $deptoDestinoNombre = $deptDestino;
        if (!$deptoDestinoId) {
            $deptoDestino = DB::connection('pgsql')
                ->table('departamentos')
                ->where('sucursal_id', $validated['sucursal_destino_id'])
                ->where('activo', true)
                ->select('id', 'nombre')
                ->first();
            $deptoDestinoId     = $deptoDestino?->id;
            $deptoDestinoNombre = $deptoDestino?->nombre;
        }

        $traslado = Traslado::create([
            'empleado_id'                => $validated['empleado_id'],
            'solicitado_por_id'          => $jefe->id,
            'sucursal_origen_id'         => $empData?->sucursal_id,
            'sucursal_origen_nombre'     => $empData?->sucursal_nombre,
            'cargo_origen_id'            => $empData?->cargo_id,
            'cargo_origen_nombre'        => $empData?->cargo_nombre,
            'departamento_origen_id'     => $empData?->departamento_id,
            'departamento_origen_nombre' => $empData?->departamento_nombre,
            'sucursal_destino_id'        => $validated['sucursal_destino_id'],
            'sucursal_destino_nombre'    => $sucursalDestino,
            'cargo_destino_id'           => $validated['cargo_destino_id']    ?? null,
            'cargo_destino_nombre'       => $cargoDestino,
            'departamento_destino_id'    => $deptoDestinoId,
            'departamento_destino_nombre'=> $deptoDestinoNombre,
            'fecha_efectiva'             => $validated['fecha_efectiva'],
            'motivo'                     => $validated['motivo'] ?? null,
            'estado'                     => $estado,
            'aud_usuario'                => Auth::user()->email,
        ]);

        if ($estado === 'aprobado') {
            if ($traslado->fecha_efectiva->lte(now()->startOfDay())) {
                $this->ejecutarTraslado($traslado);
            }
            $this->notificarTrasladoInformatica($traslado);
        } else {
            // Notificar al gerente de la sucursal destino para que apruebe
            $this->notificarGerenteDestino($traslado);
        }

        return response()->json(['success' => true, 'data' => $traslado], 201);
    }

    /**
     * GET /api/rrhh/traslados/{id}
     */
    public function show(int $id): JsonResponse
    {
        $traslado = Traslado::findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$traslado->toArray()]);

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * PUT /api/rrhh/traslados/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $traslado = Traslado::findOrFail($id);

        $validated = $request->validate([
            'sucursal_destino_id'     => 'sometimes|integer',
            'cargo_destino_id'        => 'nullable|integer',
            'departamento_destino_id' => 'nullable|integer',
            'fecha_efectiva'          => 'sometimes|date',
            'motivo'                  => 'nullable|string|max:500',
            'estado'                  => 'sometimes|in:pendiente,aprobado,rechazado,completado',
        ]);

        // Actualizar nombres denormalizados si cambia sucursal destino
        if (isset($validated['sucursal_destino_id'])) {
            $validated['sucursal_destino_nombre'] = DB::connection('pgsql')
                ->table('sucursales')
                ->where('id', $validated['sucursal_destino_id'])
                ->value('nombre');
        }

        if (!empty($validated['cargo_destino_id'])) {
            $validated['cargo_destino_nombre'] = DB::connection('pgsql')
                ->table('cargos')
                ->where('id', $validated['cargo_destino_id'])
                ->value('nombre');
        }

        if (!empty($validated['departamento_destino_id'])) {
            $validated['departamento_destino_nombre'] = DB::connection('pgsql')
                ->table('departamentos')
                ->where('id', $validated['departamento_destino_id'])
                ->value('nombre');
        }

        $traslado->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));

        $trasladoFresh = $traslado->fresh();

        // Si el estado cambia a aprobado y la fecha efectiva ya llegó → ejecutar traslado
        if (($validated['estado'] ?? null) === 'aprobado'
            && $trasladoFresh->fecha_efectiva->lte(now()->startOfDay())) {
            $this->ejecutarTraslado($trasladoFresh);
        }

        // Notificar a Informática cuando el estado cambia a aprobado
        if (($validated['estado'] ?? null) === 'aprobado') {
            $this->notificarTrasladoInformatica($trasladoFresh);
        }

        return response()->json(['success' => true, 'data' => $trasladoFresh]);
    }

    /**
     * DELETE /api/rrhh/traslados/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        Traslado::findOrFail($id)->delete();
        return response()->json(['success' => true, 'message' => 'Traslado eliminado.']);
    }

    /**
     * Notifica al gerente de la sucursal destino que hay un traslado pendiente de aprobar.
     */
    private function notificarGerenteDestino(Traslado $traslado): void
    {
        // Buscar el jefe del departamento destino en la sucursal destino
        $gerenteId = DB::connection('pgsql')
            ->table('departamentos')
            ->where('sucursal_id', $traslado->sucursal_destino_id)
            ->where('activo', true)
            ->value('jefe_empleado_id');

        if (!$gerenteId) return;

        $gerente = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('users as u', 'u.id', '=', 'e.user_id')
            ->where('e.id', $gerenteId)
            ->select('u.email', 'e.nombres', 'e.apellidos')
            ->first();

        if (!$gerente || !$gerente->email) return;

        $enriched   = $this->enrichWithEmpleadoData([$traslado->toArray()]);
        $empNombre  = $enriched[0]['empleado_nombre'] ?? "Empleado #{$traslado->empleado_id}";
        $gerenteNombre = trim($gerente->nombres . ' ' . $gerente->apellidos);
        $baseUrl    = rtrim(config('app.frontend_rrhh_url', 'https://rrhh.cervezacadejo.com'), '/');

        \Illuminate\Support\Facades\Mail::to($gerente->email)->send(
            new \App\Mail\RRHH\SolicitudAprobacion(
                tipo:              'Traslado de Personal',
                empleadoNombre:    $empNombre,
                supervisorNombre:  $gerenteNombre,
                detalles: array_filter([
                    'Colaborador'        => $empNombre,
                    'Sucursal de origen' => $traslado->sucursal_origen_nombre ?? '—',
                    'Sucursal de destino'=> $traslado->sucursal_destino_nombre ?? '—',
                    'Fecha efectiva'     => $traslado->fecha_efectiva?->toDateString() ?? '—',
                    'Motivo'             => $traslado->motivo ?? '—',
                ]),
                linkUrl:    "{$baseUrl}/traslados",
                aprobarUrl: null,
                rechazarUrl: null,
            )
        );
    }

    /**
     * Notifica al departamento de Informática (GEN_INF) de un traslado aprobado.
     */
    private function notificarTrasladoInformatica(Traslado $traslado): void
    {
        $enriched   = $this->enrichWithEmpleadoData([$traslado->toArray()]);
        $empNombre  = $enriched[0]['empleado_nombre'] ?? "Empleado #{$traslado->empleado_id}";

        $this->notificarDepartamentoCodigo(
            codigoDept:    'GEN_INF',
            tipo:          'Traslado Aprobado',
            empleadoNombre: $empNombre,
            detalles: array_filter([
                'Colaborador'          => $empNombre,
                'Sucursal de origen'   => $traslado->sucursal_origen_nombre  ?? '—',
                'Sucursal de destino'  => $traslado->sucursal_destino_nombre ?? '—',
                'Cargo de origen'      => $traslado->cargo_origen_nombre     ?? null,
                'Cargo de destino'     => $traslado->cargo_destino_nombre    ?? null,
                'Departamento destino' => $traslado->departamento_destino_nombre ?? null,
                'Fecha efectiva'       => $traslado->fecha_efectiva?->toDateString() ?? '—',
            ]),
            rutaFrontend: 'traslados',
        );
    }

    /**
     * Ejecuta el traslado: actualiza pgsql.empleados y marca como completado.
     */
    private function ejecutarTraslado(Traslado $traslado): void
    {
        $updates = ['sucursal_id' => $traslado->sucursal_destino_id];

        if ($traslado->cargo_destino_id) {
            $updates['cargo_id'] = $traslado->cargo_destino_id;
        }

        if ($traslado->departamento_destino_id) {
            $updates['departamento_id'] = $traslado->departamento_destino_id;
        } else {
            // Fallback: auto-asignar el único departamento del restaurante destino
            $deptoId = DB::connection('pgsql')
                ->table('departamentos')
                ->where('sucursal_id', $traslado->sucursal_destino_id)
                ->where('activo', true)
                ->value('id');
            if ($deptoId) $updates['departamento_id'] = $deptoId;
        }

        DB::connection('pgsql')
            ->table('empleados')
            ->where('id', $traslado->empleado_id)
            ->update($updates);

        $traslado->update(['estado' => 'completado', 'aud_usuario' => Auth::user()->email]);
    }
}

