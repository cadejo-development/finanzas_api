<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\PlanillaAcreedor;
use App\Models\RRHH\PlanillaConfig;
use App\Models\RRHH\PlanillaOrdenDescuento;
use App\Models\RRHH\PlanillaTablaRenta;
use App\Services\RRHH\PlanillaCalculatorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class MantenimientoPlanillaController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    public function __construct(private PlanillaCalculatorService $calc) {}

    // ─── Configuración ────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas/mantenimiento/config
     */
    public function getConfig(): JsonResponse
    {
        $configs = PlanillaConfig::orderBy('clave')->get();
        return response()->json(['success' => true, 'data' => $configs]);
    }

    /**
     * PUT /api/rrhh/planillas/mantenimiento/config
     * Body: { configs: [{ id, clave, valor }] }
     */
    public function updateConfig(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $validated = $request->validate([
                'configs'          => 'required|array',
                'configs.*.id'     => 'required|integer|exists:rrhh.planilla_config,id',
                'configs.*.valor'  => 'required|numeric|min:0',
            ]);

            DB::connection('rrhh')->beginTransaction();
            try {
                foreach ($validated['configs'] as $item) {
                    PlanillaConfig::where('id', $item['id'])->update(['valor' => $item['valor']]);
                }
                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            $this->calc->clearCache();

            return response()->json([
                'success' => true,
                'message' => 'Configuración actualizada.',
                'data'    => PlanillaConfig::orderBy('clave')->get(),
            ]);
        });
    }

    // ─── Tabla de Renta ───────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas/mantenimiento/renta
     */
    public function getTablaRenta(): JsonResponse
    {
        $tabla = PlanillaTablaRenta::where('activo', true)
            ->orderBy('desde')
            ->get();
        return response()->json(['success' => true, 'data' => $tabla]);
    }

    /**
     * PUT /api/rrhh/planillas/mantenimiento/renta
     * Body: { tramos: [{ id, cuota_fija, porcentaje, sobre_exceso_de }] }
     */
    public function updateTablaRenta(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $validated = $request->validate([
                'tramos'                   => 'required|array|min:1',
                'tramos.*.id'              => 'required|integer|exists:rrhh.planilla_tabla_renta,id',
                'tramos.*.cuota_fija'      => 'required|numeric|min:0',
                'tramos.*.porcentaje'      => 'required|numeric|min:0|max:100',
                'tramos.*.sobre_exceso_de' => 'required|numeric|min:0',
            ]);

            DB::connection('rrhh')->beginTransaction();
            try {
                foreach ($validated['tramos'] as $tramo) {
                    PlanillaTablaRenta::where('id', $tramo['id'])->update([
                        'cuota_fija'      => $tramo['cuota_fija'],
                        'porcentaje'      => $tramo['porcentaje'],
                        'sobre_exceso_de' => $tramo['sobre_exceso_de'],
                    ]);
                }
                DB::connection('rrhh')->commit();
            } catch (\Throwable $e) {
                DB::connection('rrhh')->rollBack();
                throw $e;
            }

            $this->calc->clearCache();

            return response()->json([
                'success' => true,
                'message' => 'Tabla de renta actualizada.',
                'data'    => PlanillaTablaRenta::where('activo', true)->orderBy('desde')->get(),
            ]);
        });
    }

    // ─── Acreedores ───────────────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas/mantenimiento/acreedores
     */
    public function getAcreedores(): JsonResponse
    {
        $acreedores = PlanillaAcreedor::orderBy('nombre')->get();
        return response()->json(['success' => true, 'data' => $acreedores]);
    }

    /**
     * POST /api/rrhh/planillas/mantenimiento/acreedores
     */
    public function storeAcreedor(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $validated = $request->validate([
                'nombre' => 'required|string|max:150',
                'tipo'   => 'required|in:banco,comercio,judicial,otro',
            ]);

            $acreedor = PlanillaAcreedor::create(array_merge($validated, ['activo' => true]));

            return response()->json(['success' => true, 'data' => $acreedor], 201);
        });
    }

    /**
     * PUT /api/rrhh/planillas/mantenimiento/acreedores/{id}
     */
    public function updateAcreedor(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $acreedor = PlanillaAcreedor::findOrFail($id);

            $validated = $request->validate([
                'nombre' => 'sometimes|string|max:150',
                'tipo'   => 'sometimes|in:banco,comercio,judicial,otro',
            ]);

            $acreedor->update($validated);

            return response()->json(['success' => true, 'data' => $acreedor]);
        });
    }

    /**
     * PATCH /api/rrhh/planillas/mantenimiento/acreedores/{id}/toggle
     */
    public function toggleAcreedor(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $acreedor = PlanillaAcreedor::findOrFail($id);
            $acreedor->update(['activo' => !$acreedor->activo]);

            return response()->json([
                'success' => true,
                'data'    => $acreedor,
                'message' => $acreedor->activo ? 'Acreedor activado.' : 'Acreedor desactivado.',
            ]);
        });
    }

    // ─── Órdenes de Descuento ─────────────────────────────────────────────────

    /**
     * GET /api/rrhh/planillas/mantenimiento/ordenes
     */
    public function getOrdenes(Request $request): JsonResponse
    {
        $query = PlanillaOrdenDescuento::with('acreedor');

        if ($empId = $request->input('empleado_id')) {
            $query->where('empleado_id', (int) $empId);
        }
        if ($request->has('activo')) {
            $query->where('activo', filter_var($request->input('activo'), FILTER_VALIDATE_BOOLEAN));
        }

        $ordenes = $query->orderByDesc('id')->get();

        // Enriquecer con datos del empleado
        $empIds = $ordenes->pluck('empleado_id')->unique()->all();
        $empleados = [];
        if (!empty($empIds)) {
            $empleados = DB::connection('pgsql')
                ->table('empleados as e')
                ->leftJoin('cargos as c', 'e.cargo_id', '=', 'c.id')
                ->leftJoin('sucursales as s', 'e.sucursal_id', '=', 's.id')
                ->whereIn('e.id', $empIds)
                ->select('e.id', 'e.codigo', 'e.nombres', 'e.apellidos', 'c.nombre as cargo_nombre', 's.nombre as sucursal_nombre')
                ->get()
                ->keyBy('id');
        }

        $data = $ordenes->map(function ($o) use ($empleados) {
            $arr = $o->toArray();
            $emp = $empleados[$o->empleado_id] ?? null;
            $arr['empleado_nombre']  = $emp ? trim($emp->nombres . ' ' . $emp->apellidos) : null;
            $arr['empleado_codigo']  = $emp?->codigo;
            $arr['cargo_nombre']     = $emp?->cargo_nombre;
            $arr['sucursal_nombre']  = $emp?->sucursal_nombre;
            return $arr;
        });

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/planillas/mantenimiento/ordenes
     */
    public function storeOrden(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $validated = $request->validate([
                'empleado_id'     => 'required|integer|exists:pgsql.empleados,id',
                'acreedor_id'     => 'nullable|integer|exists:rrhh.planilla_acreedores,id',
                'concepto'        => 'required|string|max:200',
                'monto_quincenal' => 'required|numeric|min:0.01',
                'fecha_inicio'    => 'required|date',
                'fecha_fin'       => 'nullable|date|after_or_equal:fecha_inicio',
            ]);

            $orden = PlanillaOrdenDescuento::create(array_merge($validated, ['activo' => true]));
            $orden->load('acreedor');

            return response()->json(['success' => true, 'data' => $orden], 201);
        });
    }

    /**
     * PUT /api/rrhh/planillas/mantenimiento/ordenes/{id}
     */
    public function updateOrden(Request $request, int $id): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $orden = PlanillaOrdenDescuento::findOrFail($id);

            $validated = $request->validate([
                'acreedor_id'     => 'nullable|integer|exists:rrhh.planilla_acreedores,id',
                'concepto'        => 'sometimes|string|max:200',
                'monto_quincenal' => 'sometimes|numeric|min:0.01',
                'fecha_inicio'    => 'sometimes|date',
                'fecha_fin'       => 'nullable|date',
                'activo'          => 'sometimes|boolean',
            ]);

            $orden->update($validated);
            $orden->load('acreedor');

            return response()->json(['success' => true, 'data' => $orden]);
        });
    }

    /**
     * DELETE /api/rrhh/planillas/mantenimiento/ordenes/{id}
     * Soft delete: marca activo = false
     */
    public function deleteOrden(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $orden = PlanillaOrdenDescuento::findOrFail($id);
            $orden->update(['activo' => false]);

            return response()->json(['success' => true, 'message' => 'Orden desactivada.']);
        });
    }
}
