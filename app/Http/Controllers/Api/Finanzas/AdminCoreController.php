<?php

namespace App\Http\Controllers\Api\Finanzas;

use App\Http\Controllers\Controller;
use App\Models\Cargo;
use App\Models\CentroCosto;
use App\Models\Empleado;
use App\Models\EmpleadoJefatura;
use App\Models\Sucursal;
use App\Models\TipoJefatura;
use App\Models\TipoSucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AdminCoreController extends Controller
{
    private string $aud;

    public function __construct()
    {
        $this->aud = 'admin:' . (Auth::id() ?? 'system');
    }

    // ────────────────────────────────────────────────────────────────────────
    // SUCURSALES
    // ────────────────────────────────────────────────────────────────────────

    public function sucursalesIndex(Request $request): JsonResponse
    {
        $q = Sucursal::with('tipoSucursal:id,codigo,nombre')->orderBy('nombre');
        if ($request->filled('q')) {
            $search = '%' . $request->q . '%';
            $q->where(function ($sub) use ($search) {
                $sub->where('nombre', 'ilike', $search)
                    ->orWhere('codigo', 'ilike', $search);
            });
        }
        // Por defecto solo activas; pasar ?todas=1 para ver también las cerradas
        if (!$request->boolean('todas')) {
            $q->where(fn($s) => $s->where('activa', true)->orWhereNull('activa'));
        }
        $rows = $q->paginate($request->get('per_page', 20));
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function sucursalesStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo'           => 'required|string|max:30|unique:pgsql.sucursales,codigo',
            'nombre'           => 'required|string|max:255',
            'tipo_sucursal_id' => 'required|exists:pgsql.tipos_sucursal,id',
        ]);
        $data['aud_usuario'] = Auth::id();
        $row = Sucursal::create($data);
        $row->load('tipoSucursal:id,codigo,nombre');
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function sucursalesUpdate(Request $request, int $id): JsonResponse
    {
        $row = Sucursal::findOrFail($id);
        $data = $request->validate([
            'nombre'           => 'sometimes|string|max:255',
            'tipo_sucursal_id' => 'sometimes|exists:pgsql.tipos_sucursal,id',
        ]);
        $data['aud_usuario'] = Auth::id();
        $row->update($data);
        $row->load('tipoSucursal:id,codigo,nombre');
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function sucursalesDestroy(int $id): JsonResponse
    {
        $row = Sucursal::findOrFail($id);
        $row->delete();
        return response()->json(['success' => true]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // CENTROS DE COSTO
    // ────────────────────────────────────────────────────────────────────────

    public function centrosCostoIndex(Request $request): JsonResponse
    {
        $q = CentroCosto::with('padre:id,codigo,nombre')->orderBy('codigo');
        if ($request->filled('sucursal_id')) {
            $q->where('sucursal_id', $request->sucursal_id);
        }
        if ($request->filled('q')) {
            $search = '%' . $request->q . '%';
            $q->where(function ($sub) use ($search) {
                $sub->where('nombre', 'ilike', $search)
                    ->orWhere('codigo', 'ilike', $search);
            });
        }
        $rows = $q->paginate($request->get('per_page', 15));
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function centrosCostoStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo'      => 'required|string|max:30|unique:pgsql.centros_costo,codigo',
            'nombre'      => 'required|string|max:150',
            'padre_id'    => 'nullable|exists:pgsql.centros_costo,id',
            'sucursal_id' => 'nullable|exists:pgsql.sucursales,id',
            'activo'      => 'boolean',
        ]);
        $data['es_sub']      = isset($data['padre_id']);
        $data['aud_usuario'] = Auth::id();
        $row = CentroCosto::create($data);
        $row->load('padre:id,codigo,nombre');
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function centrosCostoUpdate(Request $request, int $id): JsonResponse
    {
        $row = CentroCosto::findOrFail($id);
        $data = $request->validate([
            'nombre'      => 'sometimes|string|max:150',
            'padre_id'    => 'nullable|exists:pgsql.centros_costo,id',
            'sucursal_id' => 'nullable|exists:pgsql.sucursales,id',
            'activo'      => 'boolean',
        ]);
        if (array_key_exists('padre_id', $data)) {
            $data['es_sub'] = !is_null($data['padre_id']);
        }
        $data['aud_usuario'] = Auth::id();
        $row->update($data);
        $row->load('padre:id,codigo,nombre');
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function centrosCostoDestroy(int $id): JsonResponse
    {
        $row = CentroCosto::findOrFail($id);
        $row->delete();
        return response()->json(['success' => true]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // CARGOS
    // ────────────────────────────────────────────────────────────────────────

    public function cargosIndex(Request $request): JsonResponse
    {
        $q = Cargo::orderBy('nombre');
        if ($request->filled('q')) {
            $search = '%' . $request->q . '%';
            $q->where(function ($sub) use ($search) {
                $sub->where('nombre', 'ilike', $search)
                    ->orWhere('codigo', 'ilike', $search);
            });
        }
        $rows = $q->paginate($request->get('per_page', 20));
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function cargosStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo' => 'required|string|max:50|unique:pgsql.cargos,codigo',
            'nombre' => 'required|string|max:150',
            'activo' => 'boolean',
        ]);
        $data['aud_usuario'] = Auth::id();
        $row = Cargo::create($data);
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function cargosUpdate(Request $request, int $id): JsonResponse
    {
        $row = Cargo::findOrFail($id);
        $data = $request->validate([
            'nombre' => 'sometimes|string|max:150',
            'activo' => 'boolean',
        ]);
        $data['aud_usuario'] = Auth::id();
        $row->update($data);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function cargosDestroy(int $id): JsonResponse
    {
        $row = Cargo::findOrFail($id);
        $row->delete();
        return response()->json(['success' => true]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // EMPLEADOS
    // ────────────────────────────────────────────────────────────────────────

    public function empleadosIndex(Request $request): JsonResponse
    {
        $q = Empleado::with(['cargo:id,codigo,nombre', 'sucursal:id,codigo,nombre'])
            ->orderBy('apellidos')
            ->orderBy('nombres');

        if ($request->filled('sucursal_id')) {
            $q->where('sucursal_id', $request->sucursal_id);
        }
        if ($request->filled('q')) {
            $search = '%' . $request->q . '%';
            $q->where(function ($sub) use ($search) {
                $sub->where('nombres', 'ilike', $search)
                    ->orWhere('apellidos', 'ilike', $search)
                    ->orWhere('codigo', 'ilike', $search)
                    ->orWhere('email', 'ilike', $search);
            });
        }

        $rows = $q->paginate($request->get('per_page', 20));
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function empleadosStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'codigo'      => 'required|string|max:20|unique:pgsql.empleados,codigo',
            'nombres'     => 'required|string|max:120',
            'apellidos'   => 'required|string|max:120',
            'email'       => 'nullable|email|max:120',
            'cargo_id'    => 'nullable|exists:pgsql.cargos,id',
            'sucursal_id' => 'nullable|exists:pgsql.sucursales,id',
            'activo'      => 'boolean',
        ]);
        $data['aud_usuario'] = Auth::id();
        $row = Empleado::create($data);
        $row->load(['cargo:id,codigo,nombre', 'sucursal:id,codigo,nombre']);
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function empleadosUpdate(Request $request, int $id): JsonResponse
    {
        $row = Empleado::findOrFail($id);
        $data = $request->validate([
            'nombres'     => 'sometimes|string|max:120',
            'apellidos'   => 'sometimes|string|max:120',
            'email'       => 'nullable|email|max:120',
            'cargo_id'    => 'nullable|exists:pgsql.cargos,id',
            'sucursal_id' => 'nullable|exists:pgsql.sucursales,id',
            'activo'      => 'boolean',
        ]);
        $data['aud_usuario'] = Auth::id();
        $row->update($data);
        $row->load(['cargo:id,codigo,nombre', 'sucursal:id,codigo,nombre']);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function empleadosDestroy(int $id): JsonResponse
    {
        $row = Empleado::findOrFail($id);
        $row->delete();
        return response()->json(['success' => true]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // EMPLEADO JEFATURAS
    // ────────────────────────────────────────────────────────────────────────

    public function jefaturasIndex(Request $request): JsonResponse
    {
        $q = EmpleadoJefatura::with([
            'empleado:id,codigo,nombres,apellidos',
            'tipoJefatura:id,codigo,nombre',
            'sucursal:id,codigo,nombre',
        ])->orderBy('id');

        if ($request->filled('sucursal_id')) {
            $q->where('sucursal_id', $request->sucursal_id);
        }

        $rows = $q->paginate($request->get('per_page', 20));
        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function jefaturasStore(Request $request): JsonResponse
    {
        $data = $request->validate([
            'empleado_id'      => 'required|exists:pgsql.empleados,id',
            'tipo_jefatura_id' => 'required|exists:pgsql.tipos_jefatura,id',
            'sucursal_id'      => 'nullable|exists:pgsql.sucursales,id',
            'activo'           => 'boolean',
        ]);
        $data['aud_usuario'] = Auth::id();
        $row = EmpleadoJefatura::create($data);
        $row->load([
            'empleado:id,codigo,nombres,apellidos',
            'tipoJefatura:id,codigo,nombre',
            'sucursal:id,codigo,nombre',
        ]);
        return response()->json(['success' => true, 'data' => $row], 201);
    }

    public function jefaturasUpdate(Request $request, int $id): JsonResponse
    {
        $row = EmpleadoJefatura::findOrFail($id);
        $data = $request->validate([
            'empleado_id'      => 'sometimes|exists:pgsql.empleados,id',
            'tipo_jefatura_id' => 'sometimes|exists:pgsql.tipos_jefatura,id',
            'sucursal_id'      => 'nullable|exists:pgsql.sucursales,id',
            'activo'           => 'boolean',
        ]);
        $data['aud_usuario'] = Auth::id();
        $row->update($data);
        $row->load([
            'empleado:id,codigo,nombres,apellidos',
            'tipoJefatura:id,codigo,nombre',
            'sucursal:id,codigo,nombre',
        ]);
        return response()->json(['success' => true, 'data' => $row]);
    }

    public function jefaturasDestroy(int $id): JsonResponse
    {
        $row = EmpleadoJefatura::findOrFail($id);
        $row->delete();
        return response()->json(['success' => true]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // TIPOS SUCURSAL (solo lectura — catálogo)
    // ────────────────────────────────────────────────────────────────────────

    public function tiposSucursalIndex(): JsonResponse
    {
        $rows = TipoSucursal::orderBy('nombre')->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }

    // ────────────────────────────────────────────────────────────────────────
    // TIPOS JEFATURA (solo lectura — catálogo)
    // ────────────────────────────────────────────────────────────────────────

    public function tiposJefaturaIndex(): JsonResponse
    {
        $rows = TipoJefatura::where('activo', true)->orderBy('nombre')->get();
        return response()->json(['success' => true, 'data' => $rows]);
    }
}
