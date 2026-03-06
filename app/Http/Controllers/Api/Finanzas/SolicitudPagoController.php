<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSolicitudPagoRequest;
use App\Http\Requests\UpdateSolicitudPagoRequest;
use App\Models\Contribuyente;
use App\Models\EstadoSolicitudPago;
use App\Models\SolicitudPago;
use App\Models\SolicitudPagoAprobacion;
use App\Models\SolicitudPagoDetalle;
use App\Services\Finanzas\AprobacionService;
use App\Services\Finanzas\CalculoImpuestosSolicitudPago;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class SolicitudPagoController extends Controller
{
    /** Mapa de códigos de rol → etiqueta legible */
    private static array $rolLabels = [
        'gerente_logistica'    => 'Gte. Logística',
        'gerente_mantenimiento'=> 'Gte. Mantenimiento',
        'gerente_sucursal'     => 'Gte. Sucursal',
        'gerencia_area'        => 'Gcia. de Área',
        'gerencia_financiera'  => 'Gcia. Financiera',
        'gerencia_general'     => 'Gcia. General',
        'admin'                => 'Admin',
    ];

    /**
     * Preview de cálculo de solicitud de pago (no guarda nada).
     * POST /api/pagos/solicitudes-pago/preview
     * Validación ligera: no requiere fechas (no se usan para calcular impuestos).
     */
    public function preview(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'contribuyente_id'               => 'required',
            'personeria'                     => 'required|string',
            'es_servicio'                    => 'required',
            'detalles'                       => 'required|array|min:1',
            'detalles.*.concepto'            => 'required|string|max:255',
            'detalles.*.cantidad'            => 'required|numeric|min:0.01',
            'detalles.*.precio_unitario'     => 'required|numeric|min:0',
            'detalles.*.centro_costo_codigo' => 'nullable|string|max:20',
            'detalles.*.etiqueta_codigo'     => 'nullable|string|max:5',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'message' => 'Datos insuficientes para calcular preview.',
                'errors'  => $validator->errors(),
            ], 422);
        }

        $data     = $validator->validated();
        $detalles = $data['detalles'];

        // 1) Subtotales por línea
        $detallesCalculados = CalculoImpuestosSolicitudPago::calcularSubtotalesDetalles($detalles);
        $subTotal = array_sum(array_column($detallesCalculados, 'subtotal'));

        // 2) Info para impuestos
        $contribuyente       = Contribuyente::find($data['contribuyente_id']);
        $contribuyenteCodigo = $contribuyente?->codigo ?? '';
        $personeria          = $data['personeria'];
        $esServicio          = filter_var($data['es_servicio'], FILTER_VALIDATE_BOOLEAN);

        // 3) Totales
        $totales = CalculoImpuestosSolicitudPago::calcularTotales(
            $contribuyenteCodigo,
            $personeria,
            $esServicio,
            $subTotal
        );

        return response()->json([
            'success' => true,
            'data' => [
                'detalles' => $detallesCalculados,
                'totales'  => $totales,
            ],
        ]);
    }

    /**
     * Listado
     * GET /api/pagos/solicitudes-pago
     */
    public function index(AprobacionService $aprobacionService): JsonResponse
    {
        $userId        = Auth::id();
        $borradoreId   = EstadoSolicitudPago::where('codigo', 'BORRADOR')->value('id');

        $solicitudes = SolicitudPago::with([
                'proveedor',
                'contribuyente',
                'formaPago',
                'estadoSolicitudPago',
                'aprobaciones' => fn($q) => $q->orderBy('nivel_orden')->orderBy('id'),
            ])
            // BORRADOR solo visible al creador; el resto de estados los ve todo el mundo
            ->where(function ($q) use ($userId, $borradoreId) {
                $q->where('estado_id', '!=', $borradoreId)
                  ->orWhere('solicitante_id', $userId);
            })
            ->orderByDesc('id')
            ->get();

        // Reparación en caliente: si una solicitud ya está ENVIADA pero nunca
        // tuvo su cadena generada (ej. fue enviada antes de implementar el sistema),
        // la generamos ahora para que el nivel y aprobador aparezcan correctamente.
        foreach ($solicitudes as $s) {
            try {
                if (
                    $s->estadoSolicitudPago?->codigo === 'ENVIADO'
                    && $s->relationLoaded('aprobaciones')
                    && $s->aprobaciones->isEmpty()
                ) {
                    $aprobacionService->generarCadena($s);
                    $s->load(['aprobaciones' => fn($q) => $q->orderBy('nivel_orden')->orderBy('id')]);
                }
            } catch (\Throwable $e) {
                // Silenciar: no romper el listado por un error de reparación
                Log::warning("generarCadena falló para solicitud {$s->id}: " . $e->getMessage());
            }
        }

        $data = $solicitudes->map(function($s) {
            $arr = $s->toArray();
            $arr['estado_codigo'] = $s->estadoSolicitudPago?->codigo ?? null;
            $arr['estado_nombre'] = $s->estadoSolicitudPago?->nombre ?? null;
            if (isset($arr['fecha_solicitud'])) {
                $arr['fecha_solicitud'] = date('d/m/Y', strtotime($arr['fecha_solicitud']));
            }

            // Calcular nivel y aprobador de decisión (nivel_orden > 0, siempre visible)
            // El "Visto Bueno" (nivel_orden=0) es un pre-requisito interno;
            // la tabla muestra el NIVEL DE DECISIÓN (1-4) y quién lo aprueba.
            $aprobaciones = $s->aprobaciones;
            // Find the first PENDING decision line (nivel_orden > 0)
            $decisionLinea = $aprobaciones->where('nivel_orden', '>', 0)
                ->where('estado', 'pendiente')
                ->sortBy('nivel_orden')
                ->first();
            // Fallback: if none pending, show the last approved decision line
            if (!$decisionLinea) {
                $decisionLinea = $aprobaciones->where('nivel_orden', '>', 0)
                    ->sortByDesc('nivel_orden')
                    ->first();
            }

            if ($decisionLinea) {
                // Extraer número de nivel desde el código (nivel_1 → 1, nivel_2 → 2…)
                $numStr = preg_replace('/[^0-9]/', '', $decisionLinea->nivel_codigo);
                $arr['nivel_actual'] = 'Nivel ' . $numStr;
                $arr['aprobador_pendiente'] = self::$rolLabels[$decisionLinea->rol_requerido]
                    ?? $decisionLinea->rol_requerido;
                $arr['aprobador_nombre'] = $decisionLinea->aprobador_nombre ?? null;
            } else {
                $arr['nivel_actual'] = null;
                $arr['aprobador_pendiente'] = null;
                $arr['aprobador_nombre'] = null;
            }

            return $arr;
        });

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Crear
     * POST /api/pagos/solicitudes-pago
     */
    public function store(StoreSolicitudPagoRequest $request): JsonResponse
    {
        $data = $request->validated();

        $detalles = $data['detalles'];
        unset($data['detalles']);

        // Generar código correlativo por fecha
        $fecha = isset($data['fecha_solicitud']) ? $data['fecha_solicitud'] : date('Y-m-d');
        $fechaBase = str_replace('-', '', $fecha);
        $countHoy = SolicitudPago::whereDate('fecha_solicitud', $fecha)->count();
        $correlativo = $countHoy + 1;
        $data['codigo'] = 'SP-' . $fechaBase . '-' . str_pad($correlativo, 3, '0', STR_PAD_LEFT);

        // Buscar estado_id por código (normalizado)
        $codigoEstado = strtoupper(trim($data['estado']));
        $estado = EstadoSolicitudPago::where('codigo', $codigoEstado)->first();
        $data['estado_id'] = $estado->id;
        unset($data['estado']);

        // 1) Subtotales por línea
        $detallesCalculados = CalculoImpuestosSolicitudPago::calcularSubtotalesDetalles($detalles);
        $subTotal = array_sum(array_column($detallesCalculados, 'subtotal'));

        // 2) Info para impuestos
        $contribuyente = Contribuyente::find($data['contribuyente_id']);
        $contribuyenteCodigo = $contribuyente?->codigo ?? '';
        $personeria = $data['personeria'];
        $esServicio = $data['es_servicio'];

        // 3) Totales
        $totales = CalculoImpuestosSolicitudPago::calcularTotales(
            $contribuyenteCodigo,
            $personeria,
            $esServicio,
            $subTotal
        );

        // 4) Guardar solicitud (registrar solicitante)
        /** @var \App\Models\User $user */
        $user = Auth::user();
        $solicitud = SolicitudPago::create(array_merge($data, $totales, [
            'solicitante_id'     => $user?->id,
            'solicitante_nombre' => $user?->name,
            'aud_usuario'        => $user?->email,
        ]));

        // 5) Guardar detalles
        foreach ($detallesCalculados as $detalle) {
            $detalle['solicitud_pago_id'] = $solicitud->id;
            $detalle['subtotal_linea'] = $detalle['subtotal'];
            unset($detalle['subtotal']);
            $detalle['centro_costo_codigo'] = $detalle['centro_costo_codigo'] ?? '';
            SolicitudPagoDetalle::create($detalle);
        }

        $solicitud->load(['detalles', 'proveedor', 'contribuyente', 'formaPago']);

        return response()->json([
            'success' => true,
            'data' => $solicitud,
        ], 201);
    }

    /**
     * Mostrar
     * GET /api/pagos/solicitudes-pago/{id}
     */
    public function show(int $id): JsonResponse
    {
        $solicitud = SolicitudPago::with(['detalles', 'proveedor', 'contribuyente', 'formaPago', 'estadoSolicitudPago'])
            ->findOrFail($id);

        $arr = $solicitud->toArray();
        $arr['estado_codigo'] = $solicitud->estadoSolicitudPago?->codigo ?? null;
        $arr['estado_nombre'] = $solicitud->estadoSolicitudPago?->nombre ?? null;
        if (isset($arr['fecha_solicitud'])) {
            $arr['fecha_solicitud'] = date('d/m/Y', strtotime($arr['fecha_solicitud']));
        }
        if (isset($arr['fecha_pago'])) {
            $arr['fecha_pago'] = date('d/m/Y', strtotime($arr['fecha_pago']));
        }

        // Cargar observaciones (líneas marcadas como 'observado' con sus comentarios)
        $arr['observaciones'] = SolicitudPagoAprobacion::where('solicitud_pago_id', $solicitud->id)
            ->where('estado', 'observado')
            ->orderBy('id', 'asc')
            ->get()
            ->map(fn ($l) => [
                'id'               => $l->id,
                'aprobador_nombre' => $l->aprobador_nombre,
                'rol_requerido'    => $l->rol_requerido,
                'rol_label'        => self::$rolLabels[$l->rol_requerido] ?? $l->rol_requerido,
                'comentario'       => $l->comentario,
                'aprobado_en'      => $l->aprobado_en?->toIso8601String(),
            ])
            ->values();

        return response()->json([
            'success' => true,
            'data' => $arr,
        ]);
    }

    /**
     * Actualizar
     * PUT/PATCH /api/pagos/solicitudes-pago/{id}
     */
    public function update(UpdateSolicitudPagoRequest $request, int $id): JsonResponse
    {
        $solicitud = SolicitudPago::with('detalles')->findOrFail($id);

        $data = $request->validated();
        $detalles = $data['detalles'] ?? null;
        unset($data['detalles']);

        // 1) Subtotales (si vienen detalles)
        $detallesCalculados = $detalles
            ? CalculoImpuestosSolicitudPago::calcularSubtotalesDetalles($detalles)
            : [];

        $subTotal = $detalles
            ? array_sum(array_column($detallesCalculados, 'subtotal'))
            : $solicitud->detalles->sum('subtotal_linea');

        // 2) Info para impuestos (si no vienen, usar lo existente)
        $contribuyenteId = $data['contribuyente_id'] ?? $solicitud->contribuyente_id;
        $contribuyente = Contribuyente::find($contribuyenteId);
        $contribuyenteCodigo = $contribuyente?->codigo ?? '';

        $personeria = $data['personeria'] ?? $solicitud->personeria;
        $esServicio = $data['es_servicio'] ?? $solicitud->es_servicio;

        // 3) Totales
        $totales = CalculoImpuestosSolicitudPago::calcularTotales(
            $contribuyenteCodigo,
            $personeria,
            $esServicio,
            $subTotal
        );

        // 4) Update solicitud
        $solicitud->update(array_merge($data, $totales));

        // 5) Si vienen detalles, reemplazar
        if ($detalles) {
            $solicitud->detalles()->delete();

            foreach ($detallesCalculados as $detalle) {
                $detalle['solicitud_pago_id'] = $solicitud->id;
                $detalle['subtotal_linea'] = $detalle['subtotal'];
                unset($detalle['subtotal']);

                SolicitudPagoDetalle::create($detalle);
            }
        }

        $solicitud->load(['detalles', 'proveedor', 'contribuyente', 'formaPago']);

        return response()->json([
            'success' => true,
            'data' => $solicitud,
        ]);
    }

    /**
     * Eliminar
     * DELETE /api/pagos/solicitudes-pago/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $solicitud = SolicitudPago::findOrFail($id);

        $solicitud->detalles()->delete();
        $solicitud->adjuntos()->delete(); // si existe la relación
        $solicitud->delete();

        return response()->json([
            'success' => true,
            'message' => 'Solicitud de pago eliminada',
        ]);
    }

    /**
     * Enviar solicitud al flujo de aprobación (BORRADOR → ENVIADO + genera cadena).
     * POST /api/pagos/solicitudes-pago/{id}/enviar
     */
    public function enviar(int $id, AprobacionService $aprobacionService): JsonResponse
    {
        $solicitud = SolicitudPago::with(['estadoSolicitudPago'])->findOrFail($id);

        // Solo se puede enviar si está en BORRADOR
        if ($solicitud->estadoSolicitudPago?->codigo !== 'BORRADOR') {
            return response()->json([
                'success' => false,
                'message' => 'Solo se pueden enviar solicitudes en estado BORRADOR.',
            ], 422);
        }

        // Cambiar estado a ENVIADO
        $estadoEnviado = EstadoSolicitudPago::where('codigo', 'ENVIADO')->firstOrFail();
        $solicitud->update(['estado_id' => $estadoEnviado->id]);

        // Generar cadena de aprobación
        $aprobacionService->generarCadena($solicitud);

        $solicitud->load(['estadoSolicitudPago', 'aprobaciones']);

        return response()->json([
            'success' => true,
            'message' => 'Solicitud enviada al flujo de aprobación.',
            'data'    => $solicitud,
        ]);
    }
}