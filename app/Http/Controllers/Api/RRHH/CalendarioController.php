<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Incapacidad;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Vacacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CalendarioController extends RRHHBaseController
{
    /**
     * Devuelve todos los eventos del equipo para el mes/año indicados.
     * GET /api/rrhh/calendario?anio=2026&mes=4
     *
     * Cada evento tiene:
     *   id, tipo, empleado_id, empleado_nombre, sucursal_nombre,
     *   fecha_inicio (YYYY-MM-DD), fecha_fin (YYYY-MM-DD),
     *   titulo, estado, color
     */
    public function index(Request $request): JsonResponse
    {
        $anio = (int) ($request->query('anio', now()->year));
        $mes  = (int) ($request->query('mes',  now()->month));

        // Primer y último día del mes
        $inicio = \Carbon\Carbon::create($anio, $mes, 1)->startOfDay();
        $fin    = $inicio->copy()->endOfMonth()->endOfDay();

        $subordinadosIds = $this->getSubordinadosIds();

        if (empty($subordinadosIds)) {
            return response()->json(['success' => true, 'data' => []]);
        }

        // ── Permisos (campo: fecha, single-day or partial) ──────────────────
        $permisos = Permiso::with('tipoPermiso')
            ->whereIn('empleado_id', $subordinadosIds)
            ->whereBetween('fecha', [$inicio->toDateString(), $fin->toDateString()])
            ->whereNotIn('estado', ['rechazado'])
            ->get()
            ->map(fn($p) => [
                'id'            => 'permiso-' . $p->id,
                'tipo'          => 'permiso',
                'empleado_id'   => $p->empleado_id,
                'fecha_inicio'  => $p->fecha->toDateString(),
                'fecha_fin'     => $p->fecha->toDateString(),
                'titulo'        => $p->tipoPermiso?->nombre ?? 'Permiso',
                'detalle'       => $p->es_dia_completo
                    ? ($p->dias . ' día(s)')
                    : ($p->hora_inicio . ' – ' . $p->hora_fin),
                'estado'        => $p->estado,
                'color'         => 'amber',
            ]);

        // ── Vacaciones (fecha_inicio → fecha_fin, puede cruzar meses) ────────
        $vacaciones = Vacacion::whereIn('empleado_id', $subordinadosIds)
            ->where('fecha_inicio', '<=', $fin->toDateString())
            ->where('fecha_fin',    '>=', $inicio->toDateString())
            ->whereNotIn('estado', ['rechazado'])
            ->get()
            ->map(fn($v) => [
                'id'            => 'vacacion-' . $v->id,
                'tipo'          => 'vacacion',
                'empleado_id'   => $v->empleado_id,
                'fecha_inicio'  => $v->fecha_inicio->toDateString(),
                'fecha_fin'     => $v->fecha_fin->toDateString(),
                'titulo'        => 'Vacaciones',
                'detalle'       => $v->dias . ' día(s)',
                'estado'        => $v->estado,
                'color'         => 'green',
            ]);

        // ── Incapacidades ─────────────────────────────────────────────────────
        $incapacidades = Incapacidad::with('tipoIncapacidad')
            ->whereIn('empleado_id', $subordinadosIds)
            ->where('fecha_inicio', '<=', $fin->toDateString())
            ->where('fecha_fin',    '>=', $inicio->toDateString())
            ->get()
            ->map(fn($i) => [
                'id'            => 'incapacidad-' . $i->id,
                'tipo'          => 'incapacidad',
                'empleado_id'   => $i->empleado_id,
                'fecha_inicio'  => \Carbon\Carbon::parse($i->fecha_inicio)->toDateString(),
                'fecha_fin'     => \Carbon\Carbon::parse($i->fecha_fin)->toDateString(),
                'titulo'        => $i->tipoIncapacidad?->nombre ?? 'Incapacidad',
                'detalle'       => $i->dias . ' día(s)',
                'estado'        => $i->estado ?? 'registrado',
                'color'         => 'red',
            ]);

        // ── Unir y enriquecer con datos del empleado ─────────────────────────
        $todos  = $permisos->concat($vacaciones)->concat($incapacidades)->values()->all();
        $result = $this->enrichWithEmpleadoData($todos);

        return response()->json(['success' => true, 'data' => $result]);
    }
}
