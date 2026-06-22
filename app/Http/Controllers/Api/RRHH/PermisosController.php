<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\Permiso;
use App\Models\RRHH\TipoPermiso;
use App\Services\RRHH\SaldoCadejoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PermisosController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;
    /**
     * Lista permisos del equipo del jefe.
     * GET /api/rrhh/permisos
     */
    public function index(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $propioId = null;
        try {
            $propioId = $this->getJefeEmpleado()->id;
            $subordinadosIds = array_values(array_unique(array_merge($subordinadosIds, [$propioId])));
        } catch (\Throwable) {}

        $permisos = Permiso::with('tipoPermiso')
            ->where(function ($q) use ($subordinadosIds, $propioId) {
                $q->whereIn('empleado_id', $subordinadosIds);
                // También mostrar permisos donde este empleado es el aprobador designado,
                // por si el subordinado está en un dept que no es hijo directo en la jerarquía.
                if ($propioId) {
                    $q->orWhere('jefe_id', $propioId);
                }
            })
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
            'relacion_familiar' => 'nullable|string|max:100',
            'fecha_evento'      => 'nullable|date',
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

        // ── Validaciones por subtipo de permiso especial ──────────────────────

        // Maternidad: solo días completos, máximo 112 días, documento obligatorio
        if ($tipoPermiso?->codigo === 'maternidad') {
            if (!($validated['es_dia_completo'] ?? true)) {
                return response()->json(['success' => false, 'message' => 'La licencia por maternidad solo puede registrarse en días completos.'], 422);
            }
            $dias = (float) ($validated['dias'] ?? 0);
            if ($dias > 112) {
                return response()->json(['success' => false, 'message' => 'La licencia por maternidad tiene un máximo de 112 días (16 semanas).'], 422);
            }
            if (!$request->hasFile('archivo')) {
                return response()->json(['success' => false, 'message' => 'La licencia por maternidad requiere adjuntar la incapacidad emitida por el ISSS.'], 422);
            }
        }

        // Paternidad: 3 días, requiere fecha_evento (nacimiento), usar dentro de 15 días
        if ($tipoPermiso?->codigo === 'paternidad') {
            $dias = (float) ($validated['dias'] ?? 0);
            if ($dias > 3) {
                return response()->json(['success' => false, 'message' => 'El permiso por paternidad tiene un máximo de 3 días laborales.'], 422);
            }
            if (empty($validated['fecha_evento'])) {
                return response()->json(['success' => false, 'message' => 'Debes indicar la fecha de nacimiento para el permiso por paternidad.'], 422);
            }
            $fechaNacimiento = \Carbon\Carbon::parse($validated['fecha_evento']);
            $diasDesdeNacimiento = $fechaNacimiento->diffInDays(\Carbon\Carbon::parse($validated['fecha']), false);
            if ($diasDesdeNacimiento < 0 || $diasDesdeNacimiento > 15) {
                return response()->json(['success' => false, 'message' => 'El permiso por paternidad debe tomarse dentro de los 15 días posteriores al nacimiento.'], 422);
            }
            if (!$request->hasFile('archivo')) {
                return response()->json(['success' => false, 'message' => 'El permiso por paternidad requiere adjuntar la partida de nacimiento u otro documento válido.'], 422);
            }
        }

        // Matrimonio: 3 días, solicitud con al menos 30 días de anticipación, requiere doc posterior
        if ($tipoPermiso?->codigo === 'matrimonio') {
            $dias = (float) ($validated['dias'] ?? 0);
            if ($dias > 3) {
                return response()->json(['success' => false, 'message' => 'El permiso por matrimonio tiene un máximo de 3 días laborales.'], 422);
            }
            $fechaPermiso = \Carbon\Carbon::parse($validated['fecha']);
            $diasAnticipacion = now()->diffInDays($fechaPermiso, false);
            if ($diasAnticipacion < 30) {
                return response()->json(['success' => false, 'message' => 'El permiso por matrimonio debe solicitarse con al menos 30 días de anticipación.'], 422);
            }
        }
        // Flag para rastrear entrega posterior del acta matrimonial
        $docPosteriorPendiente = $tipoPermiso?->codigo === 'matrimonio';

        // Fallecimiento de familiar: 2 días base con posibilidad de extensión previa evaluación de RH
        if ($tipoPermiso?->codigo === 'fallecimiento_familiar') {
            if (empty($validated['relacion_familiar'])) {
                return response()->json(['success' => false, 'message' => 'Debes indicar el parentesco del familiar fallecido.'], 422);
            }
            // Extensiones >2 días requieren que RH apruebe explícitamente
            $dias = (float) ($validated['dias'] ?? 0);
            if ($dias > 2 && !$this->esAdminRrhh()) {
                return response()->json(['success' => false, 'message' => 'Las extensiones de más de 2 días por fallecimiento deben ser registradas directamente por Recursos Humanos.'], 422);
            }
        }

        // Días Cadejo: anticipación mínima de 5 días hábiles + validación de saldo
        if ($tipoPermiso?->codigo === 'dias_cadejo') {
            // Anticipación
            $fechaPermiso = \Carbon\Carbon::parse($validated['fecha']);
            $diasHabilesAnticipacion = 0;
            $cursor = now()->copy()->startOfDay();
            while ($cursor->lt($fechaPermiso->copy()->startOfDay())) {
                if (!$cursor->isWeekend()) {
                    $diasHabilesAnticipacion++;
                }
                $cursor->addDay();
            }
            if ($diasHabilesAnticipacion < 5) {
                return response()->json(['success' => false, 'message' => 'Los Días Cadejo deben solicitarse con al menos 5 días hábiles de anticipación.'], 422);
            }

            // Saldo disponible — calcular meses directamente desde fecha_ingreso
            $empFecha = \Illuminate\Support\Facades\DB::connection('pgsql')
                ->table('empleados')
                ->where('id', $validated['empleado_id'])
                ->value('fecha_ingreso');
            $mesesAntiguedad = $empFecha
                ? (int) \Carbon\Carbon::parse($empFecha)->diffInMonths(now())
                : 0;
            $diasSolicitados = (float) ($validated['dias'] ?? 1);
            $errorSaldo = SaldoCadejoService::validar(
                $validated['empleado_id'],
                $diasSolicitados,
                now()->year,
                (int) $mesesAntiguedad
            );
            if ($errorSaldo) {
                return response()->json(['success' => false, 'message' => $errorSaldo], 422);
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
            'jefe_id'                 => $aprobadorId,
            'estado'                  => $this->estadoParaEmpleado($validated['empleado_id'], $aprobadorId),
            'relacion_familiar'       => $validated['relacion_familiar'] ?? null,
            'fecha_evento'            => $validated['fecha_evento'] ?? null,
            'archivo_nombre'          => $archivoNombre,
            'archivo_ruta'            => $archivoRuta,
            'doc_posterior_pendiente' => $docPosteriorPendiente ?? false,
            'aud_usuario'             => Auth::user()->email,
            'creado_por'              => $this->creadoPor(),
        ]));

        $permiso->load('tipoPermiso');

        // Si el permiso Días Cadejo se crea directamente como aprobado, descontar el saldo
        if ($permiso->estado === 'aprobado' && $tipoPermiso?->codigo === 'dias_cadejo') {
            $anio = (int) \Carbon\Carbon::parse($permiso->fecha)->year;
            SaldoCadejoService::descontar($permiso->empleado_id, (float) ($permiso->dias ?? 1), $anio);
        }

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

        $arr       = $this->enrichWithEmpleadoData([$permiso->toArray()]);
        $empNombre = $arr[0]['empleado_nombre'] ?? "Empleado #{$validated['empleado_id']}";

        // Alerta a RH si el colaborador acumula 3+ permisos personales en los últimos 30 días
        if ($tipoPermiso?->codigo === 'PERSONAL') {
            $recientes = Permiso::where('empleado_id', $validated['empleado_id'])
                ->where('tipo_permiso_id', $validated['tipo_permiso_id'])
                ->where('fecha', '>=', now()->subDays(30)->toDateString())
                ->whereIn('estado', ['pendiente', 'aprobado'])
                ->count();

            if ($recientes >= 3) {
                try {
                    $this->notificarAdminsRrhh(
                        tipo:           'Alerta: Uso Recurrente de Permisos Personales',
                        empleadoNombre: $empNombre,
                        detalles: [
                            'Permisos personales en los últimos 30 días' => $recientes,
                            'Sucursal' => $arr[0]['sucursal_nombre'] ?? null,
                        ],
                        rutaFrontend: 'permisos',
                    );
                } catch (\Throwable $e) {
                    Log::warning('PermisosController: error notificando recurrencia', ['error' => $e->getMessage()]);
                }
            }
        }

        return response()->json(['success' => true, 'data' => $arr[0]], 201);

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
            'tipo_permiso_id'        => 'sometimes|exists:rrhh.tipos_permiso,id',
            'fecha'                  => 'sometimes|date',
            'es_dia_completo'        => 'sometimes|boolean',
            'hora_inicio'            => 'nullable|date_format:H:i',
            'hora_fin'               => 'nullable|date_format:H:i|after:hora_inicio',
            'dias'                   => 'nullable|numeric|min:0.5',
            'motivo'                 => 'nullable|string|max:500',
            'estado'                 => 'sometimes|in:pendiente,aprobado,rechazado',
            'observaciones_jefe'     => 'nullable|string|max:500',
            'doc_posterior_pendiente'=> 'sometimes|boolean',
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

        $estadoAnterior = $permiso->estado;
        $permiso->update(array_merge($validated, ['aud_usuario' => Auth::user()->email]));
        $permiso->load('tipoPermiso');

        // Gestión de saldo Días Cadejo cuando cambia el estado
        if (
            isset($validated['estado']) &&
            $validated['estado'] !== $estadoAnterior &&
            $permiso->tipoPermiso?->codigo === 'dias_cadejo'
        ) {
            $dias = (float) ($permiso->dias ?? 1);
            $anio = (int) \Carbon\Carbon::parse($permiso->fecha)->year;

            // Aprobado → descontar
            if ($validated['estado'] === 'aprobado') {
                SaldoCadejoService::descontar($permiso->empleado_id, $dias, $anio);
            }
            // Rechazado desde aprobado → devolver
            if ($estadoAnterior === 'aprobado' && in_array($validated['estado'], ['rechazado', 'pendiente'])) {
                SaldoCadejoService::devolver($permiso->empleado_id, $dias, $anio);
            }
        }

        return response()->json(['success' => true, 'data' => $permiso]);
    }

    /**
     * DELETE /api/rrhh/permisos/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $permiso = Permiso::with('tipoPermiso')->findOrFail($id);

        // Devolver saldo si era Días Cadejo aprobado
        if ($permiso->estado === 'aprobado' && $permiso->tipoPermiso?->codigo === 'dias_cadejo') {
            $anio = (int) \Carbon\Carbon::parse($permiso->fecha)->year;
            SaldoCadejoService::devolver($permiso->empleado_id, (float) ($permiso->dias ?? 1), $anio);
        }

        if ($permiso->archivo_ruta) {
            Storage::disk('s3')->delete($permiso->archivo_ruta);
        }
        $permiso->delete();
        return response()->json(['success' => true, 'message' => 'Permiso eliminado.']);
    }

    /**
     * Saldo de Días Cadejo del equipo para el año actual.
     * GET /api/rrhh/permisos/saldos-cadejo
     */
    public function saldosCadejo(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();
        $anio = now()->year;

        $saldos = SaldoCadejoService::saldosPorEmpleados($subordinadosIds, $anio);
        $data   = $this->enrichWithEmpleadoData($saldos);

        return response()->json(['success' => true, 'data' => $data]);
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

        // Query agregada: una sola consulta en vez de 2 por empleado
        $usados = Permiso::selectRaw('empleado_id, SUM(dias) as total_dias, SUM(horas_solicitadas) as total_horas')
            ->where('tipo_permiso_id', $tipoPersonal?->id)
            ->whereIn('empleado_id', $subordinadosIds)
            ->whereYear('fecha', $anio)
            ->where('estado', 'aprobado')
            ->groupBy('empleado_id')
            ->get()
            ->keyBy('empleado_id');

        $maxDias = (float) ($tipoPersonal?->max_dias ?? 5);

        $saldos = collect($subordinadosIds)->map(function ($empId) use ($anio, $usados, $maxDias) {
            $fila = $usados[$empId] ?? null;
            return [
                'empleado_id'  => $empId,
                'anio'         => $anio,
                'max_dias'     => $maxDias,
                'dias_usados'  => (float) ($fila?->total_dias ?? 0),
                'horas_usadas' => (float) ($fila?->total_horas ?? 0),
            ];
        });

        $data = $this->enrichWithEmpleadoData($saldos->all());

        return response()->json(['success' => true, 'data' => $data]);
    }
}
