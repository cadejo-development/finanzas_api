<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Http\Controllers\Controller;
use App\Models\RRHH\EstadoOrdenDescuento;
use App\Models\RRHH\OrdenDescuento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class OrdenesDescuentoController extends Controller
{
    private function baseQuery()
    {
        return OrdenDescuento::with([
            'empleado:id,nombres,apellidos,codigo',
            'acreedor:id,nombre,tipo_acreedor_id',
            'acreedor.tipo:id,nombre',
            'estado:id,nombre,color',
        ]);
    }

    public function index(Request $request)
    {
        $q = $this->baseQuery();

        if ($request->filled('empleado_id'))
            $q->where('empleado_id', $request->empleado_id);
        if ($request->filled('acreedor_id'))
            $q->where('acreedor_id', $request->acreedor_id);
        if ($request->filled('estado_id'))
            $q->where('estado_id', $request->estado_id);
        if ($request->filled('search'))
            $q->where('referencia', 'ilike', '%' . $request->search . '%');

        return response()->json($q->orderByDesc('fecha_inicio')->get());
    }

    // Órdenes del empleado autenticado
    public function misOrdenes()
    {
        $emp = auth()->user()?->empleado;
        if (!$emp) return response()->json([]);

        return response()->json(
            $this->baseQuery()->where('empleado_id', $emp->id)->orderByDesc('fecha_inicio')->get()
        );
    }

    // Catálogo de estados (para selects en UI)
    public function estados()
    {
        return response()->json(EstadoOrdenDescuento::where('activo', true)->orderBy('id')->get());
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'empleado_id' => 'required|exists:pgsql.empleados,id',
            'acreedor_id' => 'required|exists:pgsql.acreedores,id',
            'estado_id'   => 'required|exists:pgsql.estados_orden_descuento,id',
            'monto_q1'    => 'required|numeric|min:0',
            'monto_q2'    => 'required|numeric|min:0',
            'referencia'  => 'nullable|string|max:100',
            'fecha_inicio'=> 'required|date',
            'fecha_fin'   => 'nullable|date|after_or_equal:fecha_inicio',
            'notas'       => 'nullable|string',
        ]);
        $this->validarMontos($data);
        $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
        $orden = OrdenDescuento::create($data);
        return response()->json($this->baseQuery()->find($orden->id), 201);
    }

    public function update(Request $request, int $id)
    {
        $orden = OrdenDescuento::findOrFail($id);
        $data  = $request->validate([
            'empleado_id' => 'required|exists:pgsql.empleados,id',
            'acreedor_id' => 'required|exists:pgsql.acreedores,id',
            'estado_id'   => 'required|exists:pgsql.estados_orden_descuento,id',
            'monto_q1'    => 'required|numeric|min:0',
            'monto_q2'    => 'required|numeric|min:0',
            'referencia'  => 'nullable|string|max:100',
            'fecha_inicio'=> 'required|date',
            'fecha_fin'   => 'nullable|date|after_or_equal:fecha_inicio',
            'notas'       => 'nullable|string',
        ]);
        $this->validarMontos($data);
        $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
        $orden->update($data);
        return response()->json($this->baseQuery()->find($orden->id));
    }

    private function validarMontos(array $data): void
    {
        if (($data['monto_q1'] + $data['monto_q2']) <= 0) {
            abort(422, 'El monto total debe ser mayor a cero.');
        }
    }

    public function cambiarEstado(Request $request, int $id)
    {
        $orden = OrdenDescuento::findOrFail($id);
        $data  = $request->validate(['estado_id' => 'required|exists:pgsql.estados_orden_descuento,id']);
        $data['aud_usuario'] = auth()->user()?->name ?? 'sistema';
        $orden->update($data);
        return response()->json($this->baseQuery()->find($orden->id));
    }

    // ── Subir documento original ──────────────────────────────────────────────
    public function subirDocumento(Request $request, int $id)
    {
        $orden = OrdenDescuento::findOrFail($id);
        $request->validate(['documento' => 'required|file|mimes:pdf,jpg,jpeg,png|max:20480']);

        if ($orden->documento_path) {
            Storage::disk('local')->delete($orden->documento_path);
        }

        $file = $request->file('documento');
        $path = $file->store("rrhh/descuentos/{$id}", 'local');

        $orden->update([
            'documento_path'   => $path,
            'documento_nombre' => $file->getClientOriginalName(),
            'aud_usuario'      => auth()->user()?->name ?? 'sistema',
        ]);

        return response()->json($this->baseQuery()->find($orden->id));
    }

    // ── Descargar documento original ──────────────────────────────────────────
    public function descargarDocumento(int $id)
    {
        $orden = OrdenDescuento::findOrFail($id);
        abort_unless(
            $orden->documento_path && Storage::disk('local')->exists($orden->documento_path),
            404, 'Documento no encontrado'
        );
        return Storage::disk('local')->download($orden->documento_path, $orden->documento_nombre ?? 'documento.pdf');
    }

    // ── Registrar finiquito ───────────────────────────────────────────────────
    public function finiquitar(Request $request, int $id)
    {
        $orden = OrdenDescuento::findOrFail($id);
        $request->validate([
            'fecha_finiquito'     => 'required|date',
            'documento_finiquito' => 'nullable|file|mimes:pdf,jpg,jpeg,png|max:20480',
        ]);

        $update = [
            'fecha_finiquito'   => $request->fecha_finiquito,
            'finiquito_usuario' => auth()->user()?->name ?? 'sistema',
            'aud_usuario'       => auth()->user()?->name ?? 'sistema',
        ];

        if ($request->hasFile('documento_finiquito')) {
            if ($orden->documento_finiquito_path) {
                Storage::disk('local')->delete($orden->documento_finiquito_path);
            }
            $file  = $request->file('documento_finiquito');
            $path  = $file->store("rrhh/descuentos/{$id}/finiquito", 'local');
            $update['documento_finiquito_path']   = $path;
            $update['documento_finiquito_nombre'] = $file->getClientOriginalName();
        }

        $estadoFiniquitada = EstadoOrdenDescuento::where('nombre', 'Finiquitada')->first();
        if ($estadoFiniquitada) {
            $update['estado_id'] = $estadoFiniquitada->id;
        }

        $orden->update($update);
        return response()->json($this->baseQuery()->find($orden->id));
    }

    // ── Descargar documento finiquito ─────────────────────────────────────────
    public function descargarFiniquito(int $id)
    {
        $orden = OrdenDescuento::findOrFail($id);
        abort_unless(
            $orden->documento_finiquito_path && Storage::disk('local')->exists($orden->documento_finiquito_path),
            404, 'Documento de finiquito no encontrado'
        );
        return Storage::disk('local')->download($orden->documento_finiquito_path, $orden->documento_finiquito_nombre ?? 'finiquito.pdf');
    }
}
