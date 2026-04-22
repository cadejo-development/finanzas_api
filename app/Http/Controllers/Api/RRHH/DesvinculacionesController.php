<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Desvinculacion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DesvinculacionesController extends RRHHBaseController
{
    /**
     * GET /api/rrhh/desvinculaciones
     * Acepta ?tipo=despido|renuncia para filtrar.
     *
     * Se filtra por procesado_por_id (quién registró) en vez de por los
     * subordinados activos actuales, para que los registros queden visibles
     * aunque el empleado ya haya sido inactivado.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Desvinculacion::with('motivo')->orderByDesc('id');

        if ($this->esAdminRrhh()) {
            // Admin ve todo, opcionalmente filtrable
        } else {
            // Jefatura: solo las que él mismo procesó
            $jefe = $this->getJefeEmpleado();
            $query->where('procesado_por_id', $jefe->id);
        }

        if ($request->filled('tipo')) {
            $query->where('tipo', $request->tipo);
        }

        $desvinculaciones = $query->get();

        // enrichWithEmpleadoData no filtra por activo, solo hace JOIN por id
        $data = $this->enrichWithEmpleadoData($desvinculaciones->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * POST /api/rrhh/desvinculaciones
     */
    public function store(Request $request): JsonResponse
    {
        $jefe = $this->getJefeEmpleado();

        $validated = $request->validate([
            'empleado_id'   => 'required|integer',
            'motivo_id'     => 'required|exists:rrhh.motivos_desvinculacion,id',
            'tipo'          => 'required|in:despido,renuncia',
            'fecha_efectiva'=> 'required|date',
            'observaciones' => 'nullable|string|max:1000',
            'archivo'       => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        if (!$this->esSubordinado($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }


        // Capturar datos del empleado para historial denormalizado
        $empData = DB::connection('pgsql')
            ->table('empleados as e')
            ->join('cargos as c', 'e.cargo_id', '=', 'c.id')
            ->join('sucursales as s', 'e.sucursal_id', '=', 's.id')
            ->where('e.id', $validated['empleado_id'])
            ->select('e.nombres', 'e.apellidos', 'c.nombre as cargo', 's.nombre as sucursal')
            ->first();

        $archivoNombre = null;
        $archivoRuta   = null;

        if ($request->hasFile('archivo')) {
            $file          = $request->file('archivo');
            $archivoNombre = $file->getClientOriginalName();
            $archivoRuta   = $file->store('rrhh/desvinculaciones', 's3');
        }

        $desvinculacion = Desvinculacion::create(array_merge($validated, [
            'procesado_por_id'  => $jefe->id,
            'empleado_nombre'   => $empData ? trim($empData->nombres . ' ' . $empData->apellidos) : null,
            'cargo_nombre'      => $empData?->cargo,
            'sucursal_nombre'   => $empData?->sucursal,
            'archivo_nombre'    => $archivoNombre,
            'archivo_ruta'      => $archivoRuta,
            'aud_usuario'       => Auth::user()->email,
        ]));

        $desvinculacion->load('motivo');

        // Notificar a los administradores de RRHH
        $tipoLabel = $validated['tipo'] === 'despido' ? 'Despido' : 'Renuncia';
        $this->notificarAdminsRrhh(
            tipo:           "Desvinculación — {$tipoLabel}",
            empleadoNombre: $desvinculacion->empleado_nombre ?? "Empleado #{$validated['empleado_id']}",
            detalles: [
                'Tipo'           => $tipoLabel,
                'Fecha efectiva' => $validated['fecha_efectiva'],
                'Motivo'         => $desvinculacion->motivo?->nombre ?? '—',
                'Cargo'          => $desvinculacion->cargo_nombre  ?? '—',
                'Sucursal'       => $desvinculacion->sucursal_nombre ?? '—',
            ],
            rutaFrontend: 'desvinculaciones',
        );

        return response()->json(['success' => true, 'data' => $desvinculacion], 201);
    }

    /**
     * GET /api/rrhh/desvinculaciones/{id}
     */
    public function show(int $id): JsonResponse
    {
        $desvinculacion = Desvinculacion::with('motivo')->findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$desvinculacion->toArray()]);

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * PUT /api/rrhh/desvinculaciones/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $desvinculacion = Desvinculacion::findOrFail($id);

        $validated = $request->validate([
            'motivo_id'     => 'sometimes|exists:rrhh.motivos_desvinculacion,id',
            'fecha_efectiva'=> 'sometimes|date',
            'observaciones' => 'nullable|string|max:1000',
        ]);

        $desvinculacion->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));
        $desvinculacion->load('motivo');

        return response()->json(['success' => true, 'data' => $desvinculacion]);
    }

    /**
     * DELETE /api/rrhh/desvinculaciones/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $desvinculacion = Desvinculacion::findOrFail($id);

        if ($desvinculacion->archivo_ruta) {
            Storage::disk('s3')->delete($desvinculacion->archivo_ruta);
        }

        $desvinculacion->delete();
        return response()->json(['success' => true, 'message' => 'Desvinculación eliminada.']);
    }

    /**
     * GET /api/rrhh/desvinculaciones/{id}/descargar
     * Devuelve una URL presignada (60 min) para descargar el adjunto.
     */
    public function descargar(int $id): JsonResponse
    {
        $desvinculacion = Desvinculacion::findOrFail($id);

        if (!$desvinculacion->archivo_ruta) {
            return response()->json(['success' => false, 'message' => 'Esta desvinculación no tiene adjunto.'], 404);
        }

        $url = $this->s3TemporaryUrl($desvinculacion->archivo_ruta, 60);

        return response()->json(['success' => true, 'url' => $url, 'nombre' => $desvinculacion->archivo_nombre]);
    }
}
