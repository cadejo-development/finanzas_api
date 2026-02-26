<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreSolicitudPagoRequest;
use App\Http\Requests\UpdateSolicitudPagoRequest;
use App\Models\Contribuyente;
use App\Models\SolicitudPago;
use App\Models\SolicitudPagoDetalle;
use App\Services\Finanzas\CalculoImpuestosSolicitudPago;
use Illuminate\Http\JsonResponse;

class SolicitudPagoController extends Controller
{

    /**
     * Preview de cálculo de solicitud de pago (no guarda nada).
     * POST /api/pagos/solicitudes-pago/preview
     */
    public function preview(StoreSolicitudPagoRequest $request): JsonResponse
    {
        $data = $request->validated();
        $detalles = $data['detalles'];

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
    public function index(): JsonResponse
    {
        $solicitudes = SolicitudPago::with(['detalles', 'proveedor', 'contribuyente', 'formaPago', 'estadoSolicitudPago'])
            ->orderByDesc('id')
            ->get();

        $data = $solicitudes->map(function($s) {
            $arr = $s->toArray();
            $arr['estado_codigo'] = $s->estadoSolicitudPago?->codigo ?? null;
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

        // 4) Guardar solicitud
        $solicitud = SolicitudPago::create(array_merge($data, $totales));

        // 5) Guardar detalles
        foreach ($detallesCalculados as $detalle) {
            $detalle['solicitud_pago_id'] = $solicitud->id;
            $detalle['subtotal_linea'] = $detalle['subtotal'];
            unset($detalle['subtotal']);

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
}