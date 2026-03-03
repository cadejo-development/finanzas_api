<?php

namespace App\Services\Finanzas;

use App\Models\SolicitudPago;
use App\Models\SolicitudPagoDetalle;
use App\Models\Contribuyente;

class CalculoImpuestosSolicitudPago
{
    /**
     * Calcula y retorna los detalles con subtotal calculado (cantidad * precio_unitario, redondeo 2 decimales).
     * @param array $detalles
     * @return array
     */
    public static function calcularSubtotalesDetalles(array $detalles): array
    {
        foreach ($detalles as &$detalle) {
            $cantidad = isset($detalle['cantidad']) ? (float)$detalle['cantidad'] : 0;
            $precio = isset($detalle['precio_unitario']) ? (float)$detalle['precio_unitario'] : 0;
            $detalle['subtotal'] = round($cantidad * $precio, 2);
        }
        unset($detalle);
        return $detalles;
    }

    /**
     * Calcula los totales de la solicitud de pago según reglas de negocio.
     * @param string $contribuyenteCodigo
     * @param string $personeria
     * @param bool $esServicio
     * @param float $subTotal
     * @return array
     */
    public static function calcularTotales($contribuyenteCodigo, $personeria, $esServicio, $subTotal): array
    {
        $subTotal = round((float)$subTotal, 2);
        $iva = 0;
        $perc_iva_1 = 0;
        $ret_isr = 0;

        // Normalizar código contribuyente a minúsculas para comparación
        $codigo = strtolower($contribuyenteCodigo);
        if (in_array($codigo, ['no_inscrito', 'consumidor_final'])) {
            $iva = 0;
            $perc_iva_1 = 0;
        } elseif ($codigo === 'otros_contribuyentes') {
            $iva = round($subTotal * 0.13, 2);
            $perc_iva_1 = 0;
        } elseif ($codigo === 'gran_contribuyente') {
            $iva = round($subTotal * 0.13, 2);
            $perc_iva_1 = $subTotal > 100 ? round($subTotal * 0.01, 2) : 0;
        }

        if (strtolower($personeria) === 'natural' && $esServicio) {
            $ret_isr = round($subTotal * 0.10, 2);
        }

        $a_pagar = round($subTotal + $iva - $ret_isr - $perc_iva_1, 2);

        return [
            'sub_total' => $subTotal,
            'iva' => $iva,
            'ret_isr' => $ret_isr,
            'perc_iva_1' => $perc_iva_1,
            'a_pagar' => $a_pagar,
        ];
    }
}
