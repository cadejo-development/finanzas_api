<?php

namespace App\Services\Finanzas;

use App\Models\SolicitudPago;
use App\Models\SolicitudPagoAprobacion;
use App\Models\EstadoSolicitudPago;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Servicio que gestiona la cadena de aprobación de solicitudes de pago.
 *
 * OPEX:
 *  orden=0 →  $0–$149.99      gerente_sucursal
 *             $150–$499.99    gerencia_area
 *             $500–$1,999.99  gerencia_financiera
 *             $2,000+         gerencia_general
 *
 * CAPEX:
 *  orden=0 → gerente_logistica (visto bueno) + gerente_mantenimiento (visto bueno, en paralelo — AMBOS deben aprobar)
 *  orden=1 →  $0–$499.99      gerencia_area
 *             $500–$1,499.99  gerencia_financiera
 *             $1,500+         gerencia_general
 */
class AprobacionService
{
    // ─── Generación de cadena ──────────────────────────────────────────────────

    /**
     * Genera las líneas de aprobación para una solicitud al momento de enviarla.
     * Las crea con estado 'pendiente'.
     */
    public function generarCadena(SolicitudPago $solicitud): void
    {
        $tipo   = strtoupper($solicitud->tipo_gasto); // OPEX | CAPEX
        $monto  = (float) $solicitud->a_pagar;

        $lineas = $tipo === 'CAPEX'
            ? $this->lineasCapex($monto)
            : $this->lineasOpex($monto);

        foreach ($lineas as $linea) {
            SolicitudPagoAprobacion::create([
                'solicitud_pago_id' => $solicitud->id,
                'nivel_orden'       => $linea['nivel_orden'],
                'nivel_codigo'      => $linea['nivel_codigo'],
                'rol_requerido'     => $linea['rol_requerido'],
                'estado'            => 'pendiente',
                'aud_usuario'       => auth()->user()?->email,
            ]);
        }
    }

    // ─── Acciones ──────────────────────────────────────────────────────────────

    /**
     * El usuario autenticado aprueba su línea pendiente.
     * Si todos los aprobadores del mismo orden aprobaron → avanza al siguiente orden.
     * Si no quedan más órdenes → la solicitud queda APROBADO.
     */
    public function aprobar(SolicitudPago $solicitud, User $actor, ?string $comentario = null): array
    {
        return DB::connection('pagos')->transaction(function () use ($solicitud, $actor, $comentario) {
            $linea = $this->obtenerLineaPendienteParaActor($solicitud, $actor);

            if (!$linea) {
                return ['ok' => false, 'message' => 'No tienes una aprobación pendiente para esta solicitud.'];
            }

            // Registrar aprobación
            $linea->update([
                'estado'          => 'aprobado',
                'aprobador_id'    => $actor->id,
                'aprobador_nombre'=> $actor->name,
                'comentario'      => $comentario,
                'aprobado_en'     => Carbon::now(),
            ]);

            // Verificar si todas las líneas del mismo orden están aprobadas
            $pendientesEnOrden = $solicitud->aprobaciones()
                ->where('nivel_orden', $linea->nivel_orden)
                ->where('estado', 'pendiente')
                ->count();

            if ($pendientesEnOrden > 0) {
                // Todavía hay co-aprobadores pendientes en este orden (caso CAPEX visto bueno)
                return ['ok' => true, 'message' => 'Visto bueno registrado. Esperando co-aprobador.'];
            }

            // Verificar si hay un siguiente orden
            $siguienteOrden = $solicitud->aprobaciones()
                ->where('nivel_orden', '>', $linea->nivel_orden)
                ->where('estado', 'pendiente')
                ->min('nivel_orden');

            if ($siguienteOrden !== null) {
                // Hay más etapas --- no cambiamos el estado de la solicitud aún
                return ['ok' => true, 'message' => 'Etapa aprobada. Solicitud avanzó al siguiente nivel.'];
            }

            // No hay más órdenes pendientes → aprobar la solicitud
            $estadoAprobado = EstadoSolicitudPago::where('codigo', 'APROBADO')->first();
            if ($estadoAprobado) {
                $solicitud->update(['estado_id' => $estadoAprobado->id]);
            }

            return ['ok' => true, 'message' => 'Solicitud aprobada completamente.'];
        });
    }

    /**
     * El usuario autenticado rechaza su línea.
     * La solicitud entra a estado RECHAZADO y todas las demás líneas pendientes se cancelan.
     */
    public function rechazar(SolicitudPago $solicitud, User $actor, ?string $comentario = null): array
    {
        return DB::connection('pagos')->transaction(function () use ($solicitud, $actor, $comentario) {
            $linea = $this->obtenerLineaPendienteParaActor($solicitud, $actor);

            if (!$linea) {
                return ['ok' => false, 'message' => 'No tienes una aprobación pendiente para esta solicitud.'];
            }

            // Marcar esta línea como rechazada
            $linea->update([
                'estado'          => 'rechazado',
                'aprobador_id'    => $actor->id,
                'aprobador_nombre'=> $actor->name,
                'comentario'      => $comentario,
                'aprobado_en'     => Carbon::now(),
            ]);

            // Cancelar todas las demás líneas pendientes
            $solicitud->aprobaciones()
                ->where('estado', 'pendiente')
                ->update(['estado' => 'cancelado']);

            // Cambiar estado de la solicitud a RECHAZADO
            $estadoRechazado = EstadoSolicitudPago::where('codigo', 'RECHAZADO')->first();
            if ($estadoRechazado) {
                $solicitud->update(['estado_id' => $estadoRechazado->id]);
            }

            return ['ok' => true, 'message' => 'Solicitud rechazada.'];
        });
    }

    /**
     * El usuario autenticado observa (devuelve a borrador) la solicitud para que el solicitante la corrija.
     * La línea queda como 'observado', las demás pendientes se cancelan.
     * La solicitud vuelve al estado BORRADOR.
     */
    public function observar(SolicitudPago $solicitud, User $actor, string $comentario): array
    {
        return DB::connection('pagos')->transaction(function () use ($solicitud, $actor, $comentario) {
            $linea = $this->obtenerLineaPendienteParaActor($solicitud, $actor);

            if (!$linea) {
                return ['ok' => false, 'message' => 'No tienes una aprobación pendiente para esta solicitud.'];
            }

            // Marcar esta línea como observada con el comentario
            $linea->update([
                'estado'           => 'observado',
                'aprobador_id'     => $actor->id,
                'aprobador_nombre' => $actor->name,
                'comentario'       => $comentario,
                'aprobado_en'      => Carbon::now(),
            ]);

            // Cancelar todas las líneas pendientes restantes
            $solicitud->aprobaciones()
                ->where('estado', 'pendiente')
                ->update(['estado' => 'cancelado']);

            // Devolver la solicitud al estado BORRADOR
            $estadoBorrador = EstadoSolicitudPago::where('codigo', 'BORRADOR')->first();
            if ($estadoBorrador) {
                $solicitud->update(['estado_id' => $estadoBorrador->id]);
            }

            return ['ok' => true, 'message' => 'Solicitud devuelta al solicitante para revisión.'];
        });
    }

    // ─── Helpers públicos ──────────────────────────────────────────────────────

    /**
     * Retorna las líneas de aprobación de la solicitud ordenadas por nivel_orden ASC.
     */
    public function cadenaOrdenada(SolicitudPago $solicitud): \Illuminate\Database\Eloquent\Collection
    {
        return $solicitud->aprobaciones()->orderBy('nivel_orden')->orderBy('id')->get();
    }

    /**
     * Retorna las solicitudes que están pendientes de la acción del actor (según su rol activo).
     */
    public function pendientesParaActor(User $actor, int $systemId): \Illuminate\Support\Collection
    {
        // Obtener TODOS los roles del actor (sin filtrar por sistema,
        // ya que el usuario solo opera en el contexto de pagos)
        $roles = $actor->roles()->pluck('codigo')->toArray();

        if (empty($roles)) {
            return collect();
        }

        // Buscar líneas pendientes cuyo rol_requerido coincida con alguno de los roles del actor
        $aprobacionesPendientes = SolicitudPagoAprobacion::whereIn('rol_requerido', $roles)
            ->where('estado', 'pendiente')
            ->get();

        if ($aprobacionesPendientes->isEmpty()) {
            return collect();
        }

        // Filtrar: el actor sólo puede actuar si las líneas de orden anterior ya están aprobadas
        $idsValidos = $aprobacionesPendientes->filter(function (SolicitudPagoAprobacion $linea) {
            // Verificar que no haya líneas de menor orden pendientes
            $bloqueantes = SolicitudPagoAprobacion::where('solicitud_pago_id', $linea->solicitud_pago_id)
                ->where('nivel_orden', '<', $linea->nivel_orden)
                ->where('estado', 'pendiente')
                ->exists();

            return !$bloqueantes;
        })->pluck('solicitud_pago_id')->unique();

        return SolicitudPago::with([
                'estadoSolicitudPago',
                'proveedor',
                'aprobaciones' => fn($q) => $q->orderBy('nivel_orden'),
            ])
            ->whereIn('id', $idsValidos)
            ->get();
    }

    // ─── Privados ──────────────────────────────────────────────────────────────

    private function obtenerLineaPendienteParaActor(SolicitudPago $solicitud, User $actor): ?SolicitudPagoAprobacion
    {
        // Obtener roles del actor en pagos
        $roles = $actor->roles()->pluck('codigo')->toArray();

        // Buscar línea pendiente del actor
        $candidatas = $solicitud->aprobaciones()
            ->whereIn('rol_requerido', $roles)
            ->where('estado', 'pendiente')
            ->orderBy('nivel_orden')
            ->get();

        foreach ($candidatas as $linea) {
            // Verificar que no haya líneas de menor orden sin aprobar
            $bloqueantes = $solicitud->aprobaciones()
                ->where('nivel_orden', '<', $linea->nivel_orden)
                ->where('estado', 'pendiente')
                ->exists();

            if (!$bloqueantes) {
                return $linea;
            }
        }

        return null;
    }

    // ─── Matrices de aprobación ────────────────────────────────────────────────

    private function lineasOpex(float $monto): array
    {
        if ($monto < 150) {
            $rol    = 'gerente_sucursal';
            $codigo = 'nivel_1';
        } elseif ($monto < 500) {
            $rol    = 'gerencia_area';
            $codigo = 'nivel_2';
        } elseif ($monto < 2000) {
            $rol    = 'gerencia_financiera';
            $codigo = 'nivel_3';
        } else {
            $rol    = 'gerencia_general';
            $codigo = 'nivel_4';
        }

        return [
            ['nivel_orden' => 0, 'nivel_codigo' => $codigo, 'rol_requerido' => $rol],
        ];
    }

    private function lineasCapex(float $monto): array
    {
        // En CAPEX los dos visto-bueno son paralelos (ambos en nivel_orden=0)
        $lineas = [
            ['nivel_orden' => 0, 'nivel_codigo' => 'visto_bueno', 'rol_requerido' => 'gerente_logistica'],
            ['nivel_orden' => 0, 'nivel_codigo' => 'visto_bueno', 'rol_requerido' => 'gerente_mantenimiento'],
        ];

        if ($monto < 500) {
            $lineas[] = ['nivel_orden' => 1, 'nivel_codigo' => 'nivel_1', 'rol_requerido' => 'gerencia_area'];
        } elseif ($monto < 1500) {
            $lineas[] = ['nivel_orden' => 1, 'nivel_codigo' => 'nivel_2', 'rol_requerido' => 'gerencia_financiera'];
        } else {
            $lineas[] = ['nivel_orden' => 1, 'nivel_codigo' => 'nivel_3', 'rol_requerido' => 'gerencia_general'];
        }

        return $lineas;
    }
}
