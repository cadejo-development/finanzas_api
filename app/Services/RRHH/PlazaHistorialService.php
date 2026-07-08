<?php

namespace App\Services\RRHH;

use App\Models\RRHH\PlazaHistorial;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Servicio centralizado para gestionar el historial de ocupación de plazas.
 *
 * Todos los flujos que mueven empleados (bajas, traslados, ascensos,
 * cambios de puesto) deben usar este servicio en lugar de manipular
 * plaza_historial directamente.
 *
 * Motivos de entrada válidos:
 *   ingreso | traslado | ascenso | cambio_cargo | reingreso
 *
 * Motivos de salida válidos:
 *   renuncia | despido | traslado | ascenso | cambio_cargo | fallecimiento | fin_contrato
 */
class PlazaHistorialService
{
    // ── Primitivas ─────────────────────────────────────────────────────────────

    /**
     * Registra que un empleado comienza a ocupar una plaza.
     * Llamar siempre que se asigne plaza_id a un empleado.
     */
    public function ocupar(
        int $plazaId,
        int $empleadoId,
        Carbon $fechaInicio,
        string $motivoEntrada = 'ingreso',
        ?string $notas = null
    ): PlazaHistorial {
        return PlazaHistorial::create([
            'plaza_id'       => $plazaId,
            'empleado_id'    => $empleadoId,
            'motivo_entrada' => $motivoEntrada,
            'fecha_inicio'   => $fechaInicio->toDateString(),
            'fecha_fin'      => null,
            'motivo_salida'  => null,
            'notas'          => $notas,
            'aud_usuario'    => Auth::user()?->name ?? 'sistema',
        ]);
    }

    /**
     * Cierra el registro activo de la plaza (fecha_fin = null).
     * Llamar cuando el empleado deja la plaza por cualquier motivo.
     * Retorna null si la plaza no tenía registro activo.
     */
    public function liberar(
        int $plazaId,
        Carbon $fechaFin,
        string $motivoSalida,
        ?string $notas = null
    ): ?PlazaHistorial {
        $registro = PlazaHistorial::where('plaza_id', $plazaId)
            ->whereNull('fecha_fin')
            ->latest('fecha_inicio')
            ->first();

        if (!$registro) {
            return null;
        }

        $registro->update([
            'fecha_fin'     => $fechaFin->toDateString(),
            'motivo_salida' => $motivoSalida,
            'notas'         => $notas ?? $registro->notas,
            'aud_usuario'   => Auth::user()?->name ?? 'sistema',
        ]);

        return $registro->fresh();
    }

    // ── Operaciones compuestas ─────────────────────────────────────────────────

    /**
     * Baja por renuncia: libera la plaza con motivo 'renuncia'.
     */
    public function registrarRenuncia(
        int $plazaId,
        Carbon $fechaEfectiva,
        ?string $notas = null
    ): ?PlazaHistorial {
        return $this->liberar($plazaId, $fechaEfectiva, 'renuncia', $notas);
    }

    /**
     * Baja por despido: libera la plaza con motivo 'despido'.
     */
    public function registrarDespido(
        int $plazaId,
        Carbon $fechaEfectiva,
        ?string $notas = null
    ): ?PlazaHistorial {
        return $this->liberar($plazaId, $fechaEfectiva, 'despido', $notas);
    }

    /**
     * Traslado: cierra la plaza origen y abre la plaza destino.
     * Retorna ['salida' => PlazaHistorial, 'entrada' => PlazaHistorial].
     */
    public function trasladar(
        int $plazaOrigen,
        int $plazaDestino,
        int $empleadoId,
        Carbon $fecha,
        ?string $notas = null
    ): array {
        return $this->moverEmpleado($plazaOrigen, $plazaDestino, $empleadoId, $fecha, 'traslado', $notas);
    }

    /**
     * Ascenso / cambio de cargo: cierra la plaza origen y abre la plaza destino.
     * Retorna ['salida' => PlazaHistorial, 'entrada' => PlazaHistorial].
     */
    public function ascender(
        int $plazaOrigen,
        int $plazaDestino,
        int $empleadoId,
        Carbon $fecha,
        ?string $notas = null
    ): array {
        return $this->moverEmpleado($plazaOrigen, $plazaDestino, $empleadoId, $fecha, 'ascenso', $notas);
    }

    /**
     * Cambio de cargo (mismo nivel): cierra la plaza origen y abre la plaza destino.
     * Retorna ['salida' => PlazaHistorial, 'entrada' => PlazaHistorial].
     */
    public function cambiarCargo(
        int $plazaOrigen,
        int $plazaDestino,
        int $empleadoId,
        Carbon $fecha,
        ?string $notas = null
    ): array {
        return $this->moverEmpleado($plazaOrigen, $plazaDestino, $empleadoId, $fecha, 'cambio_cargo', $notas);
    }

    // ── Privado ────────────────────────────────────────────────────────────────

    private function moverEmpleado(
        int $plazaOrigen,
        int $plazaDestino,
        int $empleadoId,
        Carbon $fecha,
        string $motivo,
        ?string $notas
    ): array {
        $salida  = $this->liberar($plazaOrigen, $fecha, $motivo, $notas);
        $entrada = $this->ocupar($plazaDestino, $empleadoId, $fecha, $motivo, $notas);

        return ['salida' => $salida, 'entrada' => $entrada];
    }
}
