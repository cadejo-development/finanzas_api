<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Mail\RRHH\KpiPlantillaCreada;
use App\Models\KpiPlantilla;
use App\Models\KpiEscalaBonificacion;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

class KpiPlantillasController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = KpiPlantilla::with([
                'sucursal:id,nombre',
                'departamento:id,nombre',
                'cargos:id,nombre,codigo',
                'cargoResponsable:id,nombre',
                'escala',
            ])
            ->withCount('cargos')
            ->orderBy('nombre');

        // Filtro para responsables: solo KPIs donde su cargo es el responsable de medir
        if ($request->boolean('mis_responsabilidades')) {
            $user = $request->user();
            $cargoId = \DB::connection('pgsql')
                ->table('empleados')
                ->where('user_id', $user->id)
                ->value('cargo_id');
            $query->where('cargo_responsable_id', $cargoId);
        }

        $plantillas = $query->get()->map(fn($p) => $this->format($p));

        return response()->json($plantillas);
    }

    public function show(int $id): JsonResponse
    {
        $plantilla = KpiPlantilla::with([
                'sucursal:id,nombre',
                'departamento:id,nombre',
                'cargos:id,nombre,codigo',
                'cargoResponsable:id,nombre',
                'escala',
            ])->findOrFail($id);

        return response()->json($this->format($plantilla));
    }

    public function store(Request $request): JsonResponse
    {
        $esFinanciero = $request->user()?->hasRole('gerencia_financiera') ?? false;

        $data = $request->validate([
            'nombre'               => 'required|string|max:150',
            'descripcion'          => 'nullable|string|max:1000',
            'comentarios'          => 'nullable|string|max:2000',
            'sucursal_id'          => 'nullable|integer|exists:pgsql.sucursales,id',
            'departamento_id'      => 'nullable|integer|exists:pgsql.departamentos,id',
            'unidad_medida'        => 'required|string|max:80',
            'monto_objetivo'       => 'nullable|string|max:50',
            'tipo_meta'            => 'nullable|in:fijo,variable',
            'activo'               => 'boolean',
            'periodicidad'         => 'nullable|in:mensual,trimestral',
            'cargo_responsable_id' => 'nullable|integer|exists:pgsql.cargos,id',
            'vigencia_desde'       => 'nullable|date',
            'vigencia_hasta'       => 'nullable|date|after_or_equal:vigencia_desde',
            'cargo_ids'            => 'array',
            'cargo_ids.*'          => 'integer|exists:pgsql.cargos,id',
            'cargo_pesos'          => 'nullable|array',
            'cargo_pesos.*'        => 'nullable|numeric|min:0|max:100',
            // Dirección de cumplimiento (todos pueden definirla)
            'operador_cumplimiento' => 'nullable|in:>=,<=',
            // Monetarios: solo gerencia_financiera
            'tiene_bono'      => $esFinanciero ? 'boolean' : 'prohibited',
            'monto_bono_base' => $esFinanciero ? 'nullable|numeric|min:0' : 'prohibited',
            // Escala de rangos: todos pueden definirla
            'escala'                    => 'array',
            'escala.*.porcentaje_desde' => 'required_with:escala|numeric|min:0',
            'escala.*.descripcion'      => 'nullable|string|max:100',
            'escala.*.operador'         => 'nullable|in:>=,>,<=,<',
            'escala.*.tipo'             => 'nullable|in:porcentaje_bono,monto_fijo,medicion',
            'escala.*.valor'            => 'nullable|numeric|min:0',
        ]);

        DB::connection('pgsql')->beginTransaction();
        try {
            $plantilla = KpiPlantilla::create([
                'nombre'               => $data['nombre'],
                'descripcion'          => $data['descripcion'] ?? null,
                'comentarios'          => $data['comentarios'] ?? null,
                'sucursal_id'          => $data['sucursal_id'] ?? null,
                'departamento_id'      => $data['departamento_id'] ?? null,
                'unidad_medida'        => $data['unidad_medida'],
                'monto_objetivo'       => $data['monto_objetivo'] ?? null,
                'monto_bono_base'         => $esFinanciero && isset($data['monto_bono_base']) ? (float) $data['monto_bono_base'] : null,
                'operador_cumplimiento'   => $data['operador_cumplimiento'] ?? '>=',
                'tipo_meta'            => $data['tipo_meta'] ?? 'fijo',
                'activo'               => $data['activo'] ?? true,
                'tiene_bono'           => $esFinanciero ? ($data['tiene_bono'] ?? false) : false,
                'periodicidad'         => $data['periodicidad'] ?? 'mensual',
                'cargo_responsable_id' => $data['cargo_responsable_id'] ?? null,
                'vigencia_desde'       => $data['vigencia_desde'] ?? null,
                'vigencia_hasta'       => $data['vigencia_hasta'] ?? null,
                'aud_usuario'          => $request->user()?->email ?? 'sistema',
            ]);

            if (!empty($data['cargo_ids'])) {
                $pivotData = collect($data['cargo_ids'])
                    ->mapWithKeys(fn($id) => [
                        $id => ['peso' => $data['cargo_pesos'][$id] ?? null],
                    ])
                    ->all();
                $plantilla->cargos()->sync($pivotData);
            }

            foreach (($data['escala'] ?? []) as $i => $fila) {
                KpiEscalaBonificacion::create([
                    'kpi_plantilla_id' => $plantilla->id,
                    'porcentaje_desde' => $fila['porcentaje_desde'],
                    'descripcion'      => $fila['descripcion'] ?? null,
                    'operador'         => $fila['operador'] ?? '>=',
                    'tipo'             => $esFinanciero ? ($fila['tipo'] ?? 'monto_fijo') : 'medicion',
                    'valor'            => $esFinanciero ? (float)($fila['valor'] ?? 0) : 0,
                    'orden'            => $i,
                ]);
            }

            DB::connection('pgsql')->commit();
        } catch (\Throwable $e) {
            DB::connection('pgsql')->rollBack();
            throw $e;
        }

        $plantilla->load(['sucursal:id,nombre', 'departamento:id,nombre', 'cargos:id,nombre,codigo', 'cargoResponsable:id,nombre', 'escala']);
        $plantilla->loadCount('cargos');

        $this->notificarRrhhAdmins($plantilla, $request->user()?->name ?? 'Sistema');

        return response()->json($this->format($plantilla), 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $plantilla    = KpiPlantilla::findOrFail($id);
        $esFinanciero = $request->user()?->hasRole('gerencia_financiera') ?? false;

        $data = $request->validate([
            'nombre'               => 'required|string|max:150',
            'descripcion'          => 'nullable|string|max:1000',
            'comentarios'          => 'nullable|string|max:2000',
            'sucursal_id'          => 'nullable|integer|exists:pgsql.sucursales,id',
            'departamento_id'      => 'nullable|integer|exists:pgsql.departamentos,id',
            'unidad_medida'        => 'required|string|max:80',
            'monto_objetivo'       => 'nullable|string|max:50',
            'tipo_meta'            => 'nullable|in:fijo,variable',
            'activo'               => 'boolean',
            'periodicidad'         => 'nullable|in:mensual,trimestral',
            'cargo_responsable_id' => 'nullable|integer|exists:pgsql.cargos,id',
            'vigencia_desde'       => 'nullable|date',
            'vigencia_hasta'       => 'nullable|date|after_or_equal:vigencia_desde',
            'cargo_ids'            => 'array',
            'cargo_ids.*'          => 'integer|exists:pgsql.cargos,id',
            'cargo_pesos'          => 'nullable|array',
            'cargo_pesos.*'        => 'nullable|numeric|min:0|max:100',
            'operador_cumplimiento' => 'nullable|in:>=,<=',
            'tiene_bono'      => $esFinanciero ? 'boolean' : 'prohibited',
            'monto_bono_base' => $esFinanciero ? 'nullable|numeric|min:0' : 'prohibited',
            'escala'                    => 'array',
            'escala.*.porcentaje_desde' => 'required_with:escala|numeric|min:0',
            'escala.*.descripcion'      => 'nullable|string|max:100',
            'escala.*.operador'         => 'nullable|in:>=,>,<=,<',
            'escala.*.tipo'             => 'nullable|in:porcentaje_bono,monto_fijo,medicion',
            'escala.*.valor'            => 'nullable|numeric|min:0',
        ]);

        DB::connection('pgsql')->beginTransaction();
        try {
            $updateData = [
                'nombre'               => $data['nombre'],
                'descripcion'          => $data['descripcion'] ?? null,
                'comentarios'          => $data['comentarios'] ?? null,
                'sucursal_id'          => $data['sucursal_id'] ?? null,
                'departamento_id'      => $data['departamento_id'] ?? null,
                'unidad_medida'        => $data['unidad_medida'],
                'monto_objetivo'          => $data['monto_objetivo'] ?? null,
                'operador_cumplimiento'   => $data['operador_cumplimiento'] ?? $plantilla->operador_cumplimiento ?? '>=',
                'tipo_meta'            => $data['tipo_meta'] ?? $plantilla->tipo_meta,
                'activo'               => $data['activo'] ?? $plantilla->activo,
                'periodicidad'         => $data['periodicidad'] ?? $plantilla->periodicidad ?? 'mensual',
                'cargo_responsable_id' => $data['cargo_responsable_id'] ?? null,
                'vigencia_desde'       => $data['vigencia_desde'] ?? null,
                'vigencia_hasta'       => $data['vigencia_hasta'] ?? null,
                'aud_usuario'          => $request->user()?->email ?? 'sistema',
            ];

            if ($esFinanciero) {
                $updateData['tiene_bono']      = $data['tiene_bono'] ?? $plantilla->tiene_bono;
                $updateData['monto_bono_base'] = isset($data['monto_bono_base']) ? (float) $data['monto_bono_base'] : null;
            }

            $plantilla->update($updateData);

            $pivotData = collect($data['cargo_ids'] ?? [])
                ->mapWithKeys(fn($id) => [
                    $id => ['peso' => $data['cargo_pesos'][$id] ?? null],
                ])
                ->all();
            $plantilla->cargos()->sync($pivotData);

            $plantilla->escala()->delete();
            foreach (($data['escala'] ?? []) as $i => $fila) {
                KpiEscalaBonificacion::create([
                    'kpi_plantilla_id' => $plantilla->id,
                    'porcentaje_desde' => $fila['porcentaje_desde'],
                    'descripcion'      => $fila['descripcion'] ?? null,
                    'operador'         => $fila['operador'] ?? '>=',
                    'tipo'             => $esFinanciero ? ($fila['tipo'] ?? 'monto_fijo') : 'medicion',
                    'valor'            => $esFinanciero ? (float)($fila['valor'] ?? 0) : 0,
                    'orden'            => $i,
                ]);
            }

            DB::connection('pgsql')->commit();
        } catch (\Throwable $e) {
            DB::connection('pgsql')->rollBack();
            throw $e;
        }

        $plantilla->load(['sucursal:id,nombre', 'departamento:id,nombre', 'cargos:id,nombre,codigo', 'cargoResponsable:id,nombre', 'escala']);
        $plantilla->loadCount('cargos');

        return response()->json($this->format($plantilla));
    }

    public function toggleActivo(int $id): JsonResponse
    {
        $plantilla = KpiPlantilla::findOrFail($id);
        $plantilla->update(['activo' => !$plantilla->activo]);
        $plantilla->load(['sucursal:id,nombre', 'departamento:id,nombre', 'cargos:id,nombre,codigo', 'cargoResponsable:id,nombre', 'escala']);
        $plantilla->loadCount('cargos');

        return response()->json($this->format($plantilla));
    }

    public function destroy(int $id): JsonResponse
    {
        KpiPlantilla::findOrFail($id)->delete();
        return response()->json(['message' => 'Plantilla de KPI eliminada.']);
    }

    public function empleadosAfectados(int $id): JsonResponse
    {
        $plantilla = KpiPlantilla::with('cargos:id')->findOrFail($id);
        $cargoIds  = $plantilla->cargos->pluck('id')->toArray();

        if (empty($cargoIds)) {
            return response()->json([]);
        }

        $query = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->leftJoin('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->where('e.activo', true)
            ->whereIn('e.cargo_id', $cargoIds)
            ->orderBy('c.nombre')
            ->orderByRaw("TRIM(e.nombres || ' ' || e.apellidos)")
            ->selectRaw("e.id, TRIM(e.nombres || ' ' || e.apellidos) as nombre, c.nombre as cargo, COALESCE(s.nombre, 'Sin sucursal') as sucursal");

        if ($plantilla->sucursal_id) {
            $query->where('e.sucursal_id', $plantilla->sucursal_id);
        }
        if ($plantilla->departamento_id) {
            // Incluye empleados del departamento O con departamento NULL (el filtro principal ya es el cargo)
            $deptId = $plantilla->departamento_id;
            $query->where(function ($q) use ($deptId) {
                $q->where('e.departamento_id', $deptId)
                  ->orWhereNull('e.departamento_id');
            });
        }

        return response()->json($query->get());
    }

    public function porPuesto(Request $request): JsonResponse
    {
        $sucursalId     = $request->input('sucursal_id');
        $departamentoId = $request->input('departamento_id');

        $query = DB::connection('pgsql')
            ->table('kpi_plantilla_cargos as kpc')
            ->join('cargos as c', 'c.id', '=', 'kpc.cargo_id')
            ->join('kpi_plantillas as kp', 'kp.id', '=', 'kpc.kpi_plantilla_id')
            ->where('kp.activo', true)
            ->where('c.activo', true)
            ->orderBy('c.nombre')
            ->orderBy('kp.nombre')
            ->select([
                'c.id as cargo_id',
                'c.nombre as cargo_nombre',
                'c.codigo as cargo_codigo',
                'kp.id as kpi_id',
                'kp.nombre as kpi_nombre',
                'kp.unidad_medida',
                'kp.periodicidad',
                'kp.tiene_bono',
                'kp.monto_bono_base',
                'kpc.peso',
            ]);

        if ($sucursalId) {
            $query->where('kp.sucursal_id', (int) $sucursalId);
        }
        if ($departamentoId) {
            $query->where('kp.departamento_id', (int) $departamentoId);
        }

        $rows = $query->get();

        // Cargar escalas agrupadas por kpi_id
        $kpiIds = $rows->pluck('kpi_id')->unique()->values()->toArray();
        $escalas = empty($kpiIds) ? collect() : DB::connection('pgsql')
            ->table('kpi_escala_bonificacion')
            ->whereIn('kpi_plantilla_id', $kpiIds)
            ->orderBy('porcentaje_desde')
            ->get()
            ->groupBy('kpi_plantilla_id');

        // Agrupar por cargo
        $resultado = $rows->groupBy('cargo_id')->map(function ($kpis) use ($escalas) {
            $first     = $kpis->first();
            $pesoTotal = round($kpis->sum(fn($k) => (float) ($k->peso ?? 0)), 2);

            return [
                'cargo_id'     => $first->cargo_id,
                'cargo_nombre' => $first->cargo_nombre,
                'cargo_codigo' => $first->cargo_codigo,
                'peso_total'   => $pesoTotal,
                'kpis'         => $kpis->map(fn($k) => [
                    'id'              => $k->kpi_id,
                    'nombre'          => $k->kpi_nombre,
                    'unidad_medida'   => $k->unidad_medida,
                    'periodicidad'    => $k->periodicidad ?? 'mensual',
                    'tiene_bono'      => (bool) $k->tiene_bono,
                    'monto_bono_base' => $k->monto_bono_base !== null ? (float) $k->monto_bono_base : null,
                    'peso'            => $k->peso !== null ? (float) $k->peso : null,
                    'escala'          => ($escalas[$k->kpi_id] ?? collect())->map(fn($e) => [
                        'porcentaje_desde' => (float) $e->porcentaje_desde,
                        'descripcion'      => $e->descripcion,
                        'operador'         => $e->operador ?? '>=',
                        'tipo'             => $e->tipo,
                        'valor'            => $e->valor !== null ? (float) $e->valor : null,
                    ])->values(),
                ])->values(),
            ];
        })->values();

        return response()->json($resultado);
    }

    public function cargosDisponibles(Request $request): JsonResponse
    {
        $sucursalId     = $request->input('sucursal_id');
        $departamentoId = $request->input('departamento_id');

        $countWhere    = 'e2.cargo_id = c.id AND e2.activo = true';
        $countBindings = [];
        if ($sucursalId) {
            $countWhere .= ' AND e2.sucursal_id = ?';
            $countBindings[] = (int) $sucursalId;
        }
        if ($departamentoId) {
            $countWhere .= ' AND e2.departamento_id = ?';
            $countBindings[] = (int) $departamentoId;
        }

        $query = DB::connection('pgsql')
            ->table('cargos as c')
            ->where('c.activo', true)
            ->orderBy('c.nombre')
            ->selectRaw(
                'c.id, c.nombre, c.codigo, (SELECT COUNT(*) FROM empleados e2 WHERE ' . $countWhere . ') as total_empleados',
                $countBindings
            );

        if ($departamentoId) {
            $query->where(function ($q) use ($departamentoId) {
                $q->whereExists(function ($sub) use ($departamentoId) {
                    $sub->from('cargo_plazas_autorizadas as cpa')
                        ->whereColumn('cpa.cargo_id', 'c.id')
                        ->where('cpa.departamento_id', (int) $departamentoId);
                });
                $q->orWhereExists(function ($sub) use ($departamentoId) {
                    $sub->from('departamentos as d')
                        ->join('empleados as ej', 'd.jefe_empleado_id', '=', 'ej.id')
                        ->whereColumn('ej.cargo_id', 'c.id')
                        ->where('d.id', (int) $departamentoId)
                        ->where('d.activo', true);
                });
            });
        } elseif ($sucursalId) {
            $query->whereExists(function ($sub) use ($sucursalId) {
                $sub->from('empleados as e')
                    ->whereColumn('e.cargo_id', 'c.id')
                    ->where('e.sucursal_id', (int) $sucursalId);
            });
        }

        return response()->json($query->get());
    }

    private function format(KpiPlantilla $p): array
    {
        return [
            'id'                       => $p->id,
            'nombre'                   => $p->nombre,
            'descripcion'              => $p->descripcion,
            'comentarios'              => $p->comentarios,
            'sucursal_id'              => $p->sucursal_id,
            'sucursal_nombre'          => $p->sucursal?->nombre,
            'departamento_id'          => $p->departamento_id,
            'departamento_nombre'      => $p->departamento?->nombre,
            'unidad_medida'            => $p->unidad_medida,
            'monto_objetivo'           => $p->monto_objetivo,
            'monto_bono_base'          => $p->monto_bono_base,
            'tipo_meta'                => $p->tipo_meta ?? 'fijo',
            'operador_cumplimiento'    => $p->operador_cumplimiento ?? '>=',
            'activo'                   => $p->activo,
            'tiene_bono'               => $p->tiene_bono,
            'periodicidad'             => $p->periodicidad ?? 'mensual',
            'cargo_responsable_id'     => $p->cargo_responsable_id,
            'cargo_responsable_nombre' => $p->cargoResponsable?->nombre,
            'vigencia_desde'           => $p->vigencia_desde?->format('Y-m-d'),
            'vigencia_hasta'           => $p->vigencia_hasta?->format('Y-m-d'),
            'cargos_count'             => $p->cargos_count ?? $p->cargos->count(),
            'cargos'                   => $p->cargos->map(fn($c) => [
                                             'id'     => $c->id,
                                             'nombre' => $c->nombre,
                                             'codigo' => $c->codigo,
                                             'peso'   => $c->pivot->peso,
                                         ]),
            'escala'                   => $p->escala->map(fn($e) => [
                                             'id'                => $e->id,
                                             'porcentaje_desde'  => $e->porcentaje_desde,
                                             'descripcion'       => $e->descripcion,
                                             'operador'          => $e->operador ?? '>=',
                                             'tipo'              => $e->tipo,
                                             'valor'             => $e->valor,
                                             'orden'             => $e->orden,
                                         ]),
        ];
    }

    private function notificarRrhhAdmins(KpiPlantilla $plantilla, string $creadoPor): void
    {
        $admins = User::whereHas('roles', fn($q) => $q->where('roles.codigo', 'rrhh_admin'))
            ->where('activo', true)
            ->get(['id', 'name', 'email']);

        $link = config('app.rrhh_url', 'https://talentohumano.cervezacadejo.com') . '/admin/kpi-plantillas';

        foreach ($admins as $admin) {
            try {
                Mail::send(new KpiPlantillaCreada(
                    kpiNombre:       $plantilla->nombre,
                    kpiDescripcion:  $plantilla->descripcion ?? '',
                    creadoPor:       $creadoPor,
                    sucursalNombre:  $plantilla->sucursal?->nombre,
                    tieneBono:       $plantilla->tiene_bono,
                    linkUrl:         $link,
                ));
            } catch (\Throwable) {
                // No interrumpir el flujo si el correo falla
            }
        }
    }
}
