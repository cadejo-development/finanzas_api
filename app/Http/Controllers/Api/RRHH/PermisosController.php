<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Permiso;
use App\Models\RRHH\SaldoVacaciones;
use App\Models\RRHH\TipoPermiso;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PermisosController extends RRHHBaseController
{
    /**
     * Lista permisos del equipo del jefe.
     * GET /api/rrhh/permisos
     */
    public function index(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        // Incluir al propio jefe para que vea sus propias solicitudes
        try {
            $propioId = $this->getJefeEmpleado()->id;
            $subordinadosIds = array_values(array_unique(array_merge($subordinadosIds, [$propioId])));
        } catch (\Throwable) {}

        $permisos = Permiso::with('tipoPermiso')
            ->whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('id')
            ->get();

        $data = $this->enrichWithEmpleadoData($permisos->toArray());

        return response()->json(['success' => true, 'data' => $data]);
    }

    /**
     * Crea un nuevo permiso.
     * POST /api/rrhh/permisos
     */
    public function store(Request $request): JsonResponse
    {
        try {

        $validated = $request->validate([
            'empleado_id'       => 'required|integer',
            'tipo_permiso_id'   => 'required|exists:rrhh.tipos_permiso,id',
            'fecha'             => 'required|date',
            'es_dia_completo'   => 'boolean',
            'hora_inicio'       => 'nullable|date_format:H:i|required_if:es_dia_completo,false',
            'hora_fin'          => 'nullable|date_format:H:i|required_if:es_dia_completo,false|after:hora_inicio',
            'dias'              => 'nullable|numeric|min:0.5|required_if:es_dia_completo,true',
            'motivo'            => 'nullable|string|max:500',
            'observaciones_jefe'=> 'nullable|string|max:500',
            'archivo'           => 'nullable|file|mimes:pdf,jpg,jpeg,png,doc,docx|max:10240',
        ]);

        if (!$this->puedeGestionar($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        // Calcular horas si es parcial
        if (!($validated['es_dia_completo'] ?? true)) {
            $inicio = \Carbon\Carbon::createFromFormat('H:i', $validated['hora_inicio']);
            $fin    = \Carbon\Carbon::createFromFormat('H:i', $validated['hora_fin']);
            $validated['horas_solicitadas'] = round($inicio->floatDiffInHours($fin), 2);
            $validated['dias'] = null;
        } else {
            $validated['hora_inicio'] = null;
            $validated['hora_fin']    = null;
            $validated['horas_solicitadas'] = null;
        }

        // ── Validaciones de negocio por tipo de permiso ───────────────────────
        $tipoPermiso = TipoPermiso::find($validated['tipo_permiso_id']);

        // Consulta médica: máximo 4 horas por permiso
        if ($tipoPermiso?->codigo === 'consulta_medica') {
            $horas = (float) ($validated['horas_solicitadas'] ?? 0);
            if ($horas > 4) {
                return response()->json([
                    'success' => false,
                    'message' => 'La consulta médica tiene un máximo de 4 horas por permiso.',
                ], 422);
            }
            if ($horas <= 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'La consulta médica debe registrarse en horas (permiso parcial).',
                ], 422);
            }
        }

        // Permiso personal: máximo según max_dias del tipo (default 5 días/año)
        if ($tipoPermiso?->codigo === 'PERSONAL') {
            $maxDias    = (float) ($tipoPermiso->max_dias ?? 5);
            $diasUsados = Permiso::where('empleado_id', $validated['empleado_id'])
                ->where('tipo_permiso_id', $validated['tipo_permiso_id'])
                ->whereYear('fecha', now()->year)
                ->where('estado', 'aprobado')
                ->sum('dias');

            $diasSolicitados = (float) ($validated['dias'] ?? 0);
            $disponibles     = max($maxDias - (float) $diasUsados, 0);

            if ($diasSolicitados > $disponibles) {
                return response()->json([
                    'success' => false,
                    'message' => "Solo quedan {$disponibles} día(s) de permiso personal disponibles para este año. Ya se han usado {$diasUsados} de {$maxDias}.",
                ], 422);
            }
        }

        // Subir adjunto a S3 si viene en la solicitud
        $archivoNombre = null;
        $archivoRuta   = null;
        if ($request->hasFile('archivo')) {
            $file          = $request->file('archivo');
            $archivoNombre = $file->getClientOriginalName();
            $archivoRuta   = $file->store('rrhh/permisos', 's3');
        }

        $aprobadorId = $this->getAprobadorPara($validated['empleado_id']);
        $permiso = Permiso::create(array_merge($validated, [
            'jefe_id'        => $aprobadorId,
            'estado'         => $this->estadoParaEmpleado($validated['empleado_id'], $aprobadorId),
            'archivo_nombre' => $archivoNombre,
            'archivo_ruta'   => $archivoRuta,
            'aud_usuario'    => Auth::user()->email,
        ]));

        $permiso->load('tipoPermiso');

        // Notify supervisor when employee submits own request (or jefe submits for themselves)
        if ($this->debeNotificar($validated['empleado_id'])) {
            $detalles = array_filter([
                'Tipo'   => $permiso->tipoPermiso?->nombre,
                'Fecha'  => $validated['fecha'],
                'Días'   => isset($validated['dias']) ? $validated['dias'] . ' día(s)' : null,
                'Horas'  => isset($validated['horas_solicitadas']) ? $validated['horas_solicitadas'] . ' hrs' : null,
                'Motivo' => $validated['motivo'] ?? null,
            ]);
            $this->notificarSolicitud($validated['empleado_id'], 'Permiso', $detalles, 'permisos', $permiso->id, 'permiso');
        }

        return response()->json(['success' => true, 'data' => $permiso], 201);

        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => collect($e->errors())->flatten()->first() ?? 'Error de validación.',
                'errors'  => $e->errors(),
            ], 422);
        } catch (\Symfony\Component\HttpKernel\Exception\HttpException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::error('PermisosController@store: ' . $e->getMessage(), [
                'user'  => Auth::user()?->email,
                'trace' => $e->getTraceAsString(),
            ]);
            return response()->json(['success' => false, 'message' => $e->getMessage()], 500);
        }
    }

    /**
     * GET /api/rrhh/permisos/{id}
     */
    public function show(int $id): JsonResponse
    {
        $permiso = Permiso::with('tipoPermiso')->findOrFail($id);
        $arr = $this->enrichWithEmpleadoData([$permiso->toArray()]);

        // Adjuntar URL presignada si existe
        if ($permiso->archivo_ruta) {
            $arr[0]['archivo_url'] = $this->s3TemporaryUrl($permiso->archivo_ruta, 60);
        }

        return response()->json(['success' => true, 'data' => $arr[0]]);
    }

    /**
     * GET /api/rrhh/permisos/{id}/descargar
     * Devuelve una URL presignada (60 min) para descargar el adjunto.
     */
    public function descargar(int $id): JsonResponse
    {
        $permiso = Permiso::findOrFail($id);

        if (!$permiso->archivo_ruta) {
            return response()->json(['success' => false, 'message' => 'Este permiso no tiene adjunto.'], 404);
        }

        $url = $this->s3TemporaryUrl($permiso->archivo_ruta, 60);

        return response()->json(['success' => true, 'url' => $url, 'nombre' => $permiso->archivo_nombre]);
    }

    /**
     * PUT /api/rrhh/permisos/{id}
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $permiso = Permiso::findOrFail($id);

        $validated = $request->validate([
            'tipo_permiso_id'    => 'sometimes|exists:rrhh.tipos_permiso,id',
            'fecha'              => 'sometimes|date',
            'es_dia_completo'    => 'sometimes|boolean',
            'hora_inicio'        => 'nullable|date_format:H:i',
            'hora_fin'           => 'nullable|date_format:H:i|after:hora_inicio',
            'dias'               => 'nullable|numeric|min:0.5',
            'motivo'             => 'nullable|string|max:500',
            'estado'             => 'sometimes|in:pendiente,aprobado,rechazado',
            'observaciones_jefe' => 'nullable|string|max:500',
        ]);

        // Recalcular horas si cambia a parcial
        if (isset($validated['es_dia_completo']) && !$validated['es_dia_completo']) {
            $inicio = $validated['hora_inicio'] ?? $permiso->hora_inicio;
            $fin    = $validated['hora_fin']    ?? $permiso->hora_fin;
            if ($inicio && $fin) {
                $validated['horas_solicitadas'] = round(
                    \Carbon\Carbon::createFromFormat('H:i', $inicio)
                        ->floatDiffInHours(\Carbon\Carbon::createFromFormat('H:i', $fin)),
                    2
                );
                $validated['dias'] = null;
            }
        }

        $permiso->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));
        $permiso->load('tipoPermiso');

        return response()->json(['success' => true, 'data' => $permiso]);
    }

    /**
     * DELETE /api/rrhh/permisos/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $permiso = Permiso::findOrFail($id);
        if ($permiso->archivo_ruta) {
            Storage::disk('s3')->delete($permiso->archivo_ruta);
        }
        $permiso->delete();
        return response()->json(['success' => true, 'message' => 'Permiso eliminado.']);
    }

    /**
     * Saldo de permisos personales del equipo para el año actual.
     * GET /api/rrhh/permisos/saldos
     */
    public function saldos(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();
        $anio = now()->year;

        // Tipo permiso personal
        $tipoPersonal = TipoPermiso::where('codigo', 'PERSONAL')->first();

        $saldos = collect($subordinadosIds)->map(function ($empId) use ($anio, $tipoPersonal) {
            $diasUsados = Permiso::where('empleado_id', $empId)
                ->where('tipo_permiso_id', $tipoPersonal?->id)
                ->whereYear('fecha', $anio)
                ->where('estado', 'aprobado')
                ->sum('dias');

            $horasUsadas = Permiso::where('empleado_id', $empId)
                ->where('tipo_permiso_id', $tipoPersonal?->id)
                ->whereYear('fecha', $anio)
                ->where('estado', 'aprobado')
                ->sum('horas_solicitadas');

            return [
                'empleado_id'  => $empId,
                'anio'         => $anio,
                'max_dias'     => $tipoPersonal?->max_dias ?? 5,
                'dias_usados'  => (float) $diasUsados,
                'horas_usadas' => (float) $horasUsadas,
            ];
        });

        $data = $this->enrichWithEmpleadoData($saldos->all());

        return response()->json(['success' => true, 'data' => $data]);
    }
}
