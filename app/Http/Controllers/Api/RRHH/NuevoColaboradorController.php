<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\ExpedienteDatosPersonales;
use App\Models\RRHH\ExpedienteDocumento;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NuevoColaboradorController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    // ─────────────────────────────────────────────────────────────────────────
    // GET /api/rrhh/admin/sucursales
    // Lista de sucursales activas para selects del formulario.
    // ─────────────────────────────────────────────────────────────────────────
    public function sucursales(): JsonResponse
    {
        $rows = DB::connection('pgsql')
            ->table('sucursales')
            ->where('activo', true)
            ->orderBy('nombre')
            ->select('id', 'nombre', 'codigo')
            ->get();

        return response()->json(['success' => true, 'data' => $rows]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // POST /api/rrhh/admin/nuevo-colaborador
    // Crea el registro completo de un nuevo empleado (sin pasar por Brilo).
    // ─────────────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {

            $data = $request->validate([
                // Básicos (requeridos)
                'nombres'         => 'required|string|max:150',
                'apellidos'       => 'required|string|max:150',
                'codigo'          => 'required|string|max:20',
                'fecha_ingreso'   => 'required|date',
                'salario_base'    => 'required|numeric|min:0',
                // Opcionales básicos
                'email'           => 'nullable|email|max:150',
                'cargo_id'        => 'nullable|integer',
                'sucursal_id'     => 'nullable|integer',
                'departamento_id' => 'nullable|integer',
                'plaza_id'        => 'nullable|integer',
                'es_pensionado'   => 'boolean',
                // Datos personales (todos opcionales)
                'fecha_nacimiento'  => 'nullable|date',
                'genero'            => 'nullable|in:masculino,femenino,otro',
                'estado_civil'      => 'nullable|in:soltero,casado,divorciado,viudo,union_libre',
                // Documentos (todos opcionales)
                'dui_numero'    => 'nullable|string|max:60',
                'nit_numero'    => 'nullable|string|max:60',
                'isss_numero'   => 'nullable|string|max:60',
                'afp_entidad'   => 'nullable|string|max:60',
                'afp_numero'    => 'nullable|string|max:60',
            ]);

            // ── 1. Validar unicidad de código ──────────────────────────────
            $codigoExiste = DB::connection('pgsql')
                ->table('empleados')
                ->where('codigo', $data['codigo'])
                ->exists();

            if ($codigoExiste) {
                return response()->json([
                    'message' => 'El código de empleado ya está en uso.',
                    'errors'  => ['codigo' => ['Ya existe un empleado con ese código.']],
                ], 422);
            }

            $audUsuario = $request->user()?->email;

            // ── 2. Crear empleado en core_db ───────────────────────────────
            $empleadoId = DB::connection('pgsql')->table('empleados')->insertGetId([
                'codigo'          => $data['codigo'],
                'nombres'         => $data['nombres'],
                'apellidos'       => $data['apellidos'],
                'email'           => $data['email'] ?? null,
                'cargo_id'        => $data['cargo_id'] ?? null,
                'sucursal_id'     => $data['sucursal_id'] ?? null,
                'departamento_id' => $data['departamento_id'] ?? null,
                'plaza_id'        => $data['plaza_id'] ?? null,
                'salario_base'    => $data['salario_base'],
                'fecha_ingreso'   => $data['fecha_ingreso'],
                'es_pensionado'   => $data['es_pensionado'] ?? false,
                'activo'          => true,
                'sync_excluido'   => true,
                'aud_usuario'     => $audUsuario,
                'created_at'      => now(),
                'updated_at'      => now(),
            ]);

            // ── 3. Datos personales (opcional) ─────────────────────────────
            $datosPersonales = array_filter([
                'fecha_nacimiento' => $data['fecha_nacimiento'] ?? null,
                'genero'           => $data['genero'] ?? null,
                'estado_civil'     => $data['estado_civil'] ?? null,
            ]);

            if (!empty($datosPersonales)) {
                ExpedienteDatosPersonales::create(array_merge($datosPersonales, [
                    'empleado_id' => $empleadoId,
                    'aud_usuario' => $audUsuario,
                ]));
            }

            // ── 4. Documentos (opcionales) ─────────────────────────────────
            $docs = [
                ['tipo' => 'dui',  'numero' => $data['dui_numero']  ?? null, 'entidad' => null],
                ['tipo' => 'nit',  'numero' => $data['nit_numero']  ?? null, 'entidad' => null],
                ['tipo' => 'isss', 'numero' => $data['isss_numero'] ?? null, 'entidad' => null],
                ['tipo' => 'afp',  'numero' => $data['afp_numero']  ?? null, 'entidad' => $data['afp_entidad'] ?? null],
            ];

            foreach ($docs as $doc) {
                if (!empty($doc['numero'])) {
                    ExpedienteDocumento::create([
                        'empleado_id'    => $empleadoId,
                        'tipo'           => $doc['tipo'],
                        'numero'         => $doc['numero'],
                        'entidad_emisora'=> $doc['entidad'],
                    ]);
                }
            }

            return response()->json([
                'success'     => true,
                'message'     => 'Colaborador creado correctamente.',
                'empleado_id' => $empleadoId,
            ], 201);
        });
    }
}
