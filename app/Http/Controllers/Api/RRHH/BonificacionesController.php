<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\RRHH\Bonificacion;
use App\Models\RRHH\EstadoBonificacion;
use App\Models\RRHH\TipoBonificacion;
use Illuminate\Http\Request;

class BonificacionesController extends Controller
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    private function baseQuery()
    {
        return Bonificacion::with([
            'empleado:id,nombres,apellidos,codigo',
            'tipo:id,nombre,gravado',
            'estado:id,nombre,color',
            'solicitadoPor:id,nombres,apellidos',
            'aprobadoPor:id,nombres,apellidos',
        ]);
    }

    // Catálogos de apoyo
    public function tipos()
    {
        return response()->json(TipoBonificacion::where('activo', true)->orderBy('nombre')->get());
    }

    public function tiposStore(Request $request)
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $data = $request->validate([
                'nombre'  => 'required|string|max:120|unique:pgsql.tipos_bonificacion,nombre',
                'gravado' => 'boolean',
            ]);
            $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
            return response()->json(TipoBonificacion::create($data), 201);
        });
    }

    public function tiposUpdate(Request $request, int $id)
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $tipo = TipoBonificacion::findOrFail($id);
            $data = $request->validate([
                'nombre'  => 'required|string|max:120|unique:pgsql.tipos_bonificacion,nombre,' . $id,
                'gravado' => 'boolean',
            ]);
            $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
            $tipo->update($data);
            return response()->json($tipo);
        });
    }

    public function tiposToggle(int $id)
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $tipo = TipoBonificacion::findOrFail($id);
            $tipo->update(['activo' => !$tipo->activo, 'aud_usuario' => auth()->user()?->name ?? 'sistema']);
            return response()->json($tipo);
        });
    }

    public function estados()
    {
        return response()->json(EstadoBonificacion::where('activo', true)->orderBy('id')->get());
    }

    // Listado general (admin RRHH) o de un empleado
    public function index(Request $request)
    {
        $q = $this->baseQuery();

        if ($request->filled('empleado_id'))  $q->where('empleado_id', $request->empleado_id);
        if ($request->filled('estado_id'))    $q->where('estado_id', $request->estado_id);
        if ($request->filled('tipo_id'))      $q->where('tipo_bonificacion_id', $request->tipo_id);
        if ($request->filled('solicitado_por_id')) $q->where('solicitado_por_id', $request->solicitado_por_id);

        return response()->json($q->orderByDesc('fecha_aplicar')->get());
    }

    // Bonificaciones del empleado autenticado
    public function misBonificaciones()
    {
        $emp = auth()->user()?->empleado;
        if (!$emp) return response()->json([]);

        return response()->json(
            $this->baseQuery()->where('empleado_id', $emp->id)->orderByDesc('fecha_aplicar')->get()
        );
    }

    // Solicitar bonificación (jefe de departamento)
    public function store(Request $request)
    {
        return $this->captureAndRespond($request, function () use ($request) {
            $estadoPendiente = EstadoBonificacion::where('nombre', 'Pendiente')->firstOrFail();

            $data = $request->validate([
                'empleado_id'         => 'required|exists:pgsql.empleados,id',
                'tipo_bonificacion_id'=> 'required|exists:pgsql.tipos_bonificacion,id',
                'monto'               => 'required|numeric|min:0.01',
                'descripcion'         => 'nullable|string',
                'fecha_aplicar'       => 'required|date',
            ]);

            $solicitante = auth()->user()?->empleado;

            $data['estado_id']        = $estadoPendiente->id;
            $data['solicitado_por_id']= $solicitante?->id ?? $data['empleado_id'];
            $data['aud_usuario']      = auth()->user()?->name ?? 'sistema';

            $bono = Bonificacion::create($data);
            return response()->json($this->baseQuery()->find($bono->id), 201);
        });
    }

    // Admin RRHH: aprobar
    public function aprobar(Request $request, int $id)
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $bono = Bonificacion::findOrFail($id);
            $estadoAprobada = EstadoBonificacion::where('nombre', 'Aprobada')->firstOrFail();

            $data = $request->validate(['notas_aprobacion' => 'nullable|string']);

            $bono->update([
                'estado_id'        => $estadoAprobada->id,
                'aprobado_por_id'  => auth()->user()?->empleado?->id,
                'notas_aprobacion' => $data['notas_aprobacion'] ?? null,
                'aud_usuario'      => auth()->user()?->name ?? 'sistema',
            ]);
            return response()->json($this->baseQuery()->find($bono->id));
        });
    }

    // Admin RRHH: rechazar
    public function rechazar(Request $request, int $id)
    {
        return $this->captureAndRespond($request, function () use ($request, $id) {
            $bono = Bonificacion::findOrFail($id);
            $estadoRechazada = EstadoBonificacion::where('nombre', 'Rechazada')->firstOrFail();

            $data = $request->validate(['notas_aprobacion' => 'nullable|string']);

            $bono->update([
                'estado_id'        => $estadoRechazada->id,
                'aprobado_por_id'  => auth()->user()?->empleado?->id,
                'notas_aprobacion' => $data['notas_aprobacion'] ?? null,
                'aud_usuario'      => auth()->user()?->name ?? 'sistema',
            ]);
            return response()->json($this->baseQuery()->find($bono->id));
        });
    }

    // Admin RRHH: marcar como aplicada en planilla
    public function aplicar(int $id)
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            $bono = Bonificacion::findOrFail($id);
            $estadoAplicada = EstadoBonificacion::where('nombre', 'Aplicada')->firstOrFail();

            $bono->update([
                'estado_id'   => $estadoAplicada->id,
                'aud_usuario' => auth()->user()?->name ?? 'sistema',
            ]);
            return response()->json($this->baseQuery()->find($bono->id));
        });
    }
}
