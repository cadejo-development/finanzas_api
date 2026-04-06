<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Amonestacion;
use App\Models\RRHH\CambioSalarial;
use App\Models\RRHH\Desvinculacion;
use App\Models\RRHH\Incapacidad;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Traslado;
use App\Models\RRHH\Vacacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HistorialController extends RRHHBaseController
{
    /**
     * Historial unificado de todas las acciones del equipo.
     * GET /api/rrhh/historial
     *
     * Filtros opcionales (query string):
     *   tipo        = permiso|vacacion|incapacidad|amonestacion|traslado|desvinculacion|cambio_salarial
     *   estado      = pendiente|aprobado|rechazado|registrado
     *   empleado_id = integer
     *   desde       = YYYY-MM-DD
     *   hasta       = YYYY-MM-DD
     */
    public function index(Request $request): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        if (empty($subordinadosIds)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        $tipo       = $request->query('tipo');
        $estado     = $request->query('estado');
        $empleadoId = $request->query('empleado_id') ? (int) $request->query('empleado_id') : null;
        $desde      = $request->query('desde');
        $hasta      = $request->query('hasta');

        $scope = $empleadoId
            ? (in_array($empleadoId, $subordinadosIds) ? [$empleadoId] : [])
            : $subordinadosIds;

        $eventos = collect();

        // ── Permisos ─────────────────────────────────────────────────────────
        if (!$tipo || $tipo === 'permiso') {
            $q = Permiso::with('tipoPermiso')->whereIn('empleado_id', $scope);
            if ($estado)  $q->where('estado', $estado);
            if ($desde)   $q->whereDate('fecha', '>=', $desde);
            if ($hasta)   $q->whereDate('fecha', '<=', $hasta);

            $q->get()->each(function ($p) use (&$eventos) {
                $eventos->push([
                    'id'          => 'permiso-' . $p->id,
                    'tipo'        => 'permiso',
                    'tipo_label'  => 'Permiso',
                    'empleado_id' => $p->empleado_id,
                    'descripcion' => $p->tipoPermiso?->nombre ?? 'Permiso',
                    'detalle'     => $p->motivo ?? '',
                    'fecha'       => $p->fecha->toDateString(),
                    'fecha_fin'   => null,
                    'estado'      => $p->estado,
                    'color'       => 'amber',
                    'created_at'  => $p->created_at?->toDateTimeString(),
                ]);
            });
        }

        // ── Vacaciones ────────────────────────────────────────────────────────
        if (!$tipo || $tipo === 'vacacion') {
            $q = Vacacion::whereIn('empleado_id', $scope);
            if ($estado) $q->where('estado', $estado);
            if ($desde)  $q->whereDate('fecha_inicio', '>=', $desde);
            if ($hasta)  $q->whereDate('fecha_inicio', '<=', $hasta);

            $q->get()->each(function ($v) use (&$eventos) {
                $eventos->push([
                    'id'          => 'vacacion-' . $v->id,
                    'tipo'        => 'vacacion',
                    'tipo_label'  => 'Vacaciones',
                    'empleado_id' => $v->empleado_id,
                    'descripcion' => 'Vacaciones – ' . $v->dias . ' día(s)',
                    'detalle'     => $v->observaciones ?? '',
                    'fecha'       => $v->fecha_inicio->toDateString(),
                    'fecha_fin'   => $v->fecha_fin->toDateString(),
                    'estado'      => $v->estado,
                    'color'       => 'green',
                    'created_at'  => $v->created_at?->toDateTimeString(),
                ]);
            });
        }

        // ── Incapacidades ──────────────────────────────────────────────────────
        if (!$tipo || $tipo === 'incapacidad') {
            $q = Incapacidad::with('tipoIncapacidad')->whereIn('empleado_id', $scope);
            if ($estado) $q->where('estado', $estado);
            if ($desde)  $q->whereDate('fecha_inicio', '>=', $desde);
            if ($hasta)  $q->whereDate('fecha_inicio', '<=', $hasta);

            $q->get()->each(function ($i) use (&$eventos) {
                $eventos->push([
                    'id'          => 'incapacidad-' . $i->id,
                    'tipo'        => 'incapacidad',
                    'tipo_label'  => 'Incapacidad',
                    'empleado_id' => $i->empleado_id,
                    'descripcion' => $i->tipoIncapacidad?->nombre ?? 'Incapacidad',
                    'detalle'     => $i->descripcion ?? '',
                    'fecha'       => \Carbon\Carbon::parse($i->fecha_inicio)->toDateString(),
                    'fecha_fin'   => \Carbon\Carbon::parse($i->fecha_fin)->toDateString(),
                    'estado'      => $i->estado ?? 'registrado',
                    'color'       => 'red',
                    'created_at'  => $i->created_at?->toDateTimeString(),
                ]);
            });
        }

        // ── Amonestaciones ────────────────────────────────────────────────────
        if (!$tipo || $tipo === 'amonestacion') {
            $q = Amonestacion::with('tipoFalta')->whereIn('empleado_id', $scope);
            if ($desde) $q->whereDate('fecha_amonestacion', '>=', $desde);
            if ($hasta) $q->whereDate('fecha_amonestacion', '<=', $hasta);

            $q->get()->each(function ($a) use (&$eventos) {
                $eventos->push([
                    'id'          => 'amonestacion-' . $a->id,
                    'tipo'        => 'amonestacion',
                    'tipo_label'  => 'Amonestación',
                    'empleado_id' => $a->empleado_id,
                    'descripcion' => $a->tipoFalta?->nombre ?? 'Amonestación',
                    'detalle'     => $a->descripcion ?? '',
                    'fecha'       => \Carbon\Carbon::parse($a->fecha_amonestacion)->toDateString(),
                    'fecha_fin'   => null,
                    'estado'      => 'registrado',
                    'color'       => 'orange',
                    'created_at'  => $a->created_at?->toDateTimeString(),
                ]);
            });
        }

        // ── Traslados ─────────────────────────────────────────────────────────
        if (!$tipo || $tipo === 'traslado') {
            $q = Traslado::whereIn('empleado_id', $scope);
            if ($estado) $q->where('estado', $estado);
            if ($desde)  $q->whereDate('fecha_efectiva', '>=', $desde);
            if ($hasta)  $q->whereDate('fecha_efectiva', '<=', $hasta);

            $q->get()->each(function ($t) use (&$eventos) {
                $eventos->push([
                    'id'          => 'traslado-' . $t->id,
                    'tipo'        => 'traslado',
                    'tipo_label'  => 'Traslado',
                    'empleado_id' => $t->empleado_id,
                    'descripcion' => 'Traslado de personal',
                    'detalle'     => $t->motivo ?? '',
                    'fecha'       => $t->fecha_efectiva,
                    'fecha_fin'   => null,
                    'estado'      => $t->estado,
                    'color'       => 'blue',
                    'created_at'  => $t->created_at?->toDateTimeString(),
                ]);
            });
        }

        // ── Desvinculaciones ──────────────────────────────────────────────────
        if (!$tipo || $tipo === 'desvinculacion') {
            $q = Desvinculacion::with('motivo')->whereIn('empleado_id', $scope);
            if ($desde) $q->whereDate('fecha_efectiva', '>=', $desde);
            if ($hasta) $q->whereDate('fecha_efectiva', '<=', $hasta);

            $q->get()->each(function ($d) use (&$eventos) {
                $eventos->push([
                    'id'          => 'desvinculacion-' . $d->id,
                    'tipo'        => 'desvinculacion',
                    'tipo_label'  => $d->tipo === 'renuncia' ? 'Renuncia' : 'Despido',
                    'empleado_id' => $d->empleado_id,
                    'descripcion' => $d->motivo?->nombre ?? 'Desvinculación',
                    'detalle'     => $d->observaciones ?? '',
                    'fecha'       => $d->fecha_efectiva,
                    'fecha_fin'   => null,
                    'estado'      => 'registrado',
                    'color'       => 'rose',
                    'created_at'  => $d->created_at?->toDateTimeString(),
                ]);
            });
        }

        // ── Cambios salariales ────────────────────────────────────────────────
        if (!$tipo || $tipo === 'cambio_salarial') {
            $q = CambioSalarial::with('tipoAumento')->whereIn('empleado_id', $scope);
            if ($estado) $q->where('estado', $estado);
            if ($desde)  $q->whereDate('fecha_efectiva', '>=', $desde);
            if ($hasta)  $q->whereDate('fecha_efectiva', '<=', $hasta);

            $q->get()->each(function ($c) use (&$eventos) {
                $eventos->push([
                    'id'          => 'cambio_salarial-' . $c->id,
                    'tipo'        => 'cambio_salarial',
                    'tipo_label'  => $c->tipoAumento?->nombre ?? 'Cambio Salarial',
                    'empleado_id' => $c->empleado_id,
                    'descripcion' => $c->tipoAumento?->nombre ?? 'Cambio Salarial',
                    'detalle'     => $c->justificacion ?? '',
                    'fecha'       => $c->fecha_efectiva,
                    'fecha_fin'   => null,
                    'estado'      => $c->estado,
                    'color'       => 'purple',
                    'created_at'  => $c->created_at?->toDateTimeString(),
                ]);
            });
        }

        // Ordenar por fecha descendente
        $sorted = $eventos->sortByDesc('created_at')->values()->all();
        $result = $this->enrichWithEmpleadoData($sorted);

        return response()->json(['success' => true, 'data' => $result]);
    }
}
