<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\AusenciaInjustificada;
use App\Models\RRHH\Permiso;
use App\Models\RRHH\Incapacidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AusenciasController extends RRHHBaseController
{
    /**
     * GET /api/rrhh/ausencias
     * Lista ausencias injustificadas del equipo (+ alertas).
     */
    public function index(Request $request): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();

        $query = AusenciaInjustificada::whereIn('empleado_id', $subordinadosIds)
            ->orderByDesc('fecha');

        if ($empleadoId = $request->query('empleado_id')) {
            $query->where('empleado_id', (int) $empleadoId);
        }
        if ($desde = $request->query('desde')) {
            $query->where('fecha', '>=', $desde);
        }
        if ($hasta = $request->query('hasta')) {
            $query->where('fecha', '<=', $hasta);
        }

        $ausencias = $query->get();
        $data = $this->enrichWithEmpleadoData($ausencias->toArray());

        // Calcular alertas por empleado
        $alertas = $this->calcularAlertas($ausencias->toArray());

        return response()->json([
            'success' => true,
            'data'    => $data,
            'alertas' => $alertas,
        ]);
    }

    /**
     * POST /api/rrhh/ausencias
     */
    public function store(Request $request): JsonResponse
    {

        $validated = $request->validate([
            'empleado_id' => 'required|integer',
            'fecha'       => 'required|date',
            'descripcion' => 'nullable|string|max:500',
        ]);

        $jefe = $this->getJefeEmpleado();

        if (!$this->puedeGestionar($validated['empleado_id'])) {
            return response()->json([
                'success' => false,
                'message' => 'El empleado no pertenece a tu equipo.',
            ], 403);
        }

        // Verificar duplicado
        $existe = AusenciaInjustificada::where('empleado_id', $validated['empleado_id'])
            ->where('fecha', $validated['fecha'])
            ->exists();

        if ($existe) {
            return response()->json([
                'success' => false,
                'message' => 'Ya existe una ausencia registrada para este empleado en esa fecha.',
            ], 422);
        }

        $ausencia = AusenciaInjustificada::create([
            'empleado_id'      => $validated['empleado_id'],
            'registrado_por_id'=> $jefe->id,
            'fecha'            => $validated['fecha'],
            'descripcion'      => $validated['descripcion'] ?? null,
            'aud_usuario'      => Auth::user()->email,
            'creado_por'       => $this->creadoPor(),
        ]);

        $arr         = $this->enrichWithEmpleadoData([$ausencia->toArray()]);
        $empNombre   = $arr[0]['empleado_nombre'] ?? "Empleado #{$validated['empleado_id']}";
        $sucursal    = $arr[0]['sucursal_nombre']  ?? null;

        // Notificar al empleado
        try {
            $this->notificarAlEmpleado(
                empleadoId:   $validated['empleado_id'],
                tipo:         'Ausencia Injustificada',
                mensaje:      "Se ha registrado una ausencia injustificada en tu expediente. Si consideras que este registro es incorrecto, comunicate con tu jefe inmediato.",
                detalles: array_filter([
                    'Fecha'       => $validated['fecha'],
                    'Descripcion' => $validated['descripcion'] ?? null,
                ]),
                rutaFrontend: 'mi-expediente',
                pdfContent:   null,
                pdfNombre:    null,
            );
        } catch (\Throwable $e) {
            Log::warning('AusenciasController: error notificando al empleado', ['error' => $e->getMessage()]);
        }

        // Verificar umbrales y notificar a jefatura + admins RRHH si se alcanzan
        $this->verificarUmbralesYNotificar($validated['empleado_id'], $empNombre, $sucursal, $validated['fecha']);

        return response()->json(['success' => true, 'data' => $arr[0]], 201);
    }

    /**
     * DELETE /api/rrhh/ausencias/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        $ausencia = AusenciaInjustificada::findOrFail($id);

        if (!$this->puedeGestionar($ausencia->empleado_id)) {
            return response()->json(['success' => false, 'message' => 'Sin permiso.'], 403);
        }

        $ausencia->delete();
        return response()->json(['success' => true, 'message' => 'Ausencia eliminada.']);
    }

    /**
     * GET /api/rrhh/ausencias/resumen-mes
     * Devuelve alertas del mes actual para el dashboard.
     */
    public function resumenMes(): JsonResponse
    {
        $subordinadosIds = $this->getSubordinadosIds();
        $hoy   = now();
        $desde = $hoy->copy()->startOfMonth()->toDateString();
        $hasta = $hoy->toDateString();

        $ausencias = AusenciaInjustificada::whereIn('empleado_id', $subordinadosIds)
            ->whereBetween('fecha', [$desde, $hasta])
            ->orderBy('empleado_id')
            ->orderBy('fecha')
            ->get();

        $alertas = $this->calcularAlertas($ausencias->toArray());
        $data    = $this->enrichWithEmpleadoData($ausencias->toArray());

        return response()->json([
            'success'       => true,
            'data'          => $data,
            'alertas'       => $alertas,
            'total_ausencias_mes' => count($data),
        ]);
    }

    /**
     * PATCH /api/rrhh/ausencias/{id}/regularizar
     * Reclasifica una ausencia injustificada a permiso personal o incapacidad
     * cuando el colaborador presenta justificación posterior.
     */
    public function regularizar(Request $request, int $id): JsonResponse
    {
        $ausencia = AusenciaInjustificada::findOrFail($id);

        if (!$this->puedeGestionar($ausencia->empleado_id)) {
            return response()->json(['success' => false, 'message' => 'Sin permiso.'], 403);
        }

        $validated = $request->validate([
            'tipo'         => 'required|in:permiso_personal,incapacidad',
            'justificacion'=> 'required|string|min:10|max:1000',
        ]);

        $referenciaId = null;

        if ($validated['tipo'] === 'permiso_personal') {
            // Crear permiso personal real aprobado
            $tipoPersonal = \App\Models\RRHH\TipoPermiso::where('codigo', 'PERSONAL')->first();
            if ($tipoPersonal) {
                $aprobadorId = $this->getAprobadorPara($ausencia->empleado_id);
                $permiso = Permiso::create([
                    'empleado_id'     => $ausencia->empleado_id,
                    'jefe_id'         => $aprobadorId,
                    'tipo_permiso_id' => $tipoPersonal->id,
                    'fecha'           => $ausencia->fecha,
                    'es_dia_completo' => true,
                    'dias'            => 1,
                    'motivo'          => "Regularización de ausencia: {$validated['justificacion']}",
                    'estado'          => 'aprobado',
                    'aud_usuario'     => Auth::user()->email,
                    'creado_por'      => $this->creadoPor(),
                ]);
                $referenciaId = $permiso->id;
            }
        } else {
            // Buscar incapacidad existente que cubra esa fecha
            $incapacidad = Incapacidad::where('empleado_id', $ausencia->empleado_id)
                ->where('fecha_inicio', '<=', $ausencia->fecha)
                ->where('fecha_fin', '>=', $ausencia->fecha)
                ->first();
            // -1 si no hay incapacidad aún (se presentará después), ID real si ya existe
            $referenciaId = $incapacidad?->id ?? -1;
        }

        $ausencia->update([
            'cubierta_por_incapacidad_id' => $referenciaId,
            'descripcion' => ($ausencia->descripcion ? $ausencia->descripcion . ' | ' : '')
                . "Regularizada ({$validated['tipo']}): {$validated['justificacion']}",
            'aud_usuario' => Auth::user()->email,
        ]);

        $arr = $this->enrichWithEmpleadoData([$ausencia->toArray()]);

        return response()->json([
            'success' => true,
            'message' => 'Ausencia regularizada correctamente.',
            'data'    => $arr[0],
        ]);
    }

    /**
     * Verifica si el colaborador alcanzó umbrales de ausencias (2 consecutivas o 3 en el mes)
     * y envía notificación a jefatura y admins RRHH si corresponde.
     */
    private function verificarUmbralesYNotificar(int $empleadoId, string $empNombre, ?string $sucursal, string $fechaNueva): void
    {
        try {
            $mes   = \Carbon\Carbon::parse($fechaNueva)->format('Y-m');
            $desde = $mes . '-01';
            $hasta = \Carbon\Carbon::parse($fechaNueva)->endOfMonth()->toDateString();

            $ausenciasMes = AusenciaInjustificada::where('empleado_id', $empleadoId)
                ->whereBetween('fecha', [$desde, $hasta])
                ->orderBy('fecha')
                ->pluck('fecha')
                ->map(fn($f) => (string) $f)
                ->all();

            sort($ausenciasMes);
            $maxConsecutivas = 1;
            $actual = 1;
            for ($i = 1; $i < count($ausenciasMes); $i++) {
                $diff = (new \DateTime($ausenciasMes[$i]))->diff(new \DateTime($ausenciasMes[$i - 1]))->days;
                $actual = ($diff === 1) ? $actual + 1 : 1;
                $maxConsecutivas = max($maxConsecutivas, $actual);
            }
            $totalMes          = count($ausenciasMes);
            $alertaConsecutiva = $maxConsecutivas >= 2;
            $alertaMensual     = $totalMes >= 3;

            if (!$alertaConsecutiva && !$alertaMensual) return;

            $motivos = [];
            if ($alertaConsecutiva) $motivos[] = "{$maxConsecutivas} ausencias consecutivas";
            if ($alertaMensual)     $motivos[] = "{$totalMes} ausencias en el mes";

            $detalles = array_filter([
                'Colaborador'  => $empNombre,
                'Sucursal'     => $sucursal,
                'Mes'          => $mes,
                'Motivo alerta'=> implode(' | ', $motivos),
                'Referencia'   => 'Art. 50 numeral 12 Código de Trabajo SV',
            ]);

            // Notificar a admins RRHH
            $this->notificarAdminsRrhh(
                tipo:           'Alerta de Ausencias Injustificadas',
                empleadoNombre: $empNombre,
                detalles:       $detalles,
                rutaFrontend:   'ausencias',
            );

            // Notificar a la jefatura inmediata
            $this->notificarAccion(
                empleadoId:   $empleadoId,
                tipo:         'Alerta de Ausencias Injustificadas',
                detalles:     $detalles,
                rutaFrontend: 'ausencias',
            );
        } catch (\Throwable $e) {
            Log::warning('AusenciasController: error verificando umbrales', ['error' => $e->getMessage()]);
        }
    }

    /**
     * Calcula alertas por empleado:
     * - consecutivas >= 2 → alerta_consecutiva
     * - en el mes >= 3   → alerta_mensual
     */
    private function calcularAlertas(array $rows): array
    {
        // Agrupar por empleado
        $porEmpleado = [];
        foreach ($rows as $r) {
            $porEmpleado[$r['empleado_id']][] = $r['fecha'];
        }

        $alertas = [];
        foreach ($porEmpleado as $empId => $fechas) {
            sort($fechas);

            $maxConsecutivas = 1;
            $actual = 1;
            for ($i = 1; $i < count($fechas); $i++) {
                $diff = (new \DateTime($fechas[$i]))->diff(new \DateTime($fechas[$i - 1]))->days;
                if ($diff === 1) {
                    $actual++;
                    $maxConsecutivas = max($maxConsecutivas, $actual);
                } else {
                    $actual = 1;
                }
            }

            $alertaConsecutiva = $maxConsecutivas >= 2;
            $alertaMensual     = count($fechas) >= 3;

            if ($alertaConsecutiva || $alertaMensual) {
                $alertas[] = [
                    'empleado_id'        => $empId,
                    'total_ausencias'    => count($fechas),
                    'max_consecutivas'   => $maxConsecutivas,
                    'alerta_consecutiva' => $alertaConsecutiva,
                    'alerta_mensual'     => $alertaMensual,
                ];
            }
        }

        return $alertas;
    }
}
