<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\SolicitudPago;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class DashboardSolicitudesPagoController extends Controller
{
    /**
     * Resumen para dashboard de solicitudes de pago.
     * GET /api/pagos/dashboard-solicitudes-pago
     */
    public function resumen(): JsonResponse
    {
        $pendientes = SolicitudPago::whereHas('estadoSolicitudPago', function($q) {
            $q->where('codigo', 'ENVIADO');
        })->count();

        $rechazadas = SolicitudPago::whereHas('estadoSolicitudPago', function($q) {
            $q->where('codigo', 'RECHAZADO');
        })->count();

        $aprobadasHoy = SolicitudPago::whereHas('estadoSolicitudPago', function($q) {
            $q->where('codigo', 'APROBADO');
        })
        ->whereDate('fecha_aprobacion', Carbon::today())
        ->count();

        return response()->json([
            'success' => true,
            'data' => [
                'pendientes' => $pendientes,
                'aprobadas_hoy' => $aprobadasHoy,
                'rechazadas' => $rechazadas,
            ],
        ]);
    }
}
