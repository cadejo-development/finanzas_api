<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Receta;
use App\Models\RecetaIngrediente;
use App\Models\RecetaSucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RecetasController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────
    // GET /api/compras/recetas
    // Lista paginada de recetas (con ingredientes + producto).
    // Query opcional: sucursal_id → devuelve platos_semana específico
    // ──────────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $perPage    = min((int) $request->query('per_page', 20), 100);
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;

        $query = Receta::with(['ingredientes.producto'])
            ->where('activa', true)
            ->orderBy('nombre');

        // Pre-cargar configuración de platos por sucursal si se especifica
        if ($sucursalId !== null) {
            $query->with(['sucursalConfig' => fn ($q) => $q->where('sucursal_id', $sucursalId)]);
        }

        if ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }

        if ($search = $request->query('search')) {
            $query->where('nombre', 'ilike', "%{$search}%");
        }

        $pagina = $query->paginate($perPage);

        return response()->json([
            'data' => $pagina->getCollection()->map(fn ($r) => $this->formatReceta($r, $sucursalId)),
            'meta' => [
                'current_page' => $pagina->currentPage(),
                'last_page'    => $pagina->lastPage(),
                'per_page'     => $pagina->perPage(),
                'total'        => $pagina->total(),
                'from'         => $pagina->firstItem(),
                'to'           => $pagina->lastItem(),
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // GET /api/compras/recetas/{id}
    // ──────────────────────────────────────────────────────────────────────
    public function show(Request $request, int $id): JsonResponse
    {
        $sucursalId = $request->query('sucursal_id') ? (int) $request->query('sucursal_id') : null;

        $query = Receta::with(['ingredientes.producto']);
        if ($sucursalId !== null) {
            $query->with(['sucursalConfig' => fn ($q) => $q->where('sucursal_id', $sucursalId)]);
        }

        $receta = $query->findOrFail($id);
        return response()->json(['data' => $this->formatReceta($receta, $sucursalId)]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/compras/recetas
    // ──────────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre'              => 'required|string|max:150',
            'descripcion'         => 'nullable|string',
            'tipo'                => 'nullable|string|max:80',
            'platos_semana'       => 'required|integer|min:0',
            'ingredientes'        => 'array',
            'ingredientes.*.producto_id'       => 'required|integer',
            'ingredientes.*.cantidad_por_plato'=> 'required|numeric|min:0',
            'ingredientes.*.unidad'            => 'required|string|max:20',
        ]);

        $usuario = $request->user()?->email ?? 'sistema';

        $receta = DB::connection('compras')->transaction(function () use ($validated, $usuario): Receta {
            $receta = Receta::create([
                'nombre'        => $validated['nombre'],
                'descripcion'   => $validated['descripcion'] ?? null,
                'tipo'          => $validated['tipo'] ?? null,
                'platos_semana' => $validated['platos_semana'],
                'activa'        => true,
                'aud_usuario'   => $usuario,
            ]);

            foreach ($validated['ingredientes'] ?? [] as $ing) {
                RecetaIngrediente::create([
                    'receta_id'          => $receta->id,
                    'producto_id'        => $ing['producto_id'],
                    'cantidad_por_plato' => $ing['cantidad_por_plato'],
                    'unidad'             => $ing['unidad'],
                    'aud_usuario'        => $usuario,
                ]);
            }

            return $receta;
        });

        $receta->load('ingredientes.producto');
        return response()->json(['data' => $this->formatReceta($receta)], 201);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PUT /api/compras/recetas/{id}
    // ──────────────────────────────────────────────────────────────────────
    public function update(Request $request, int $id): JsonResponse
    {
        $receta = Receta::findOrFail($id);

        $validated = $request->validate([
            'nombre'              => 'sometimes|string|max:150',
            'descripcion'         => 'nullable|string',
            'tipo'                => 'nullable|string|max:80',
            'platos_semana'       => 'sometimes|integer|min:0',
            'activa'              => 'sometimes|boolean',
            'ingredientes'        => 'sometimes|array',
            'ingredientes.*.producto_id'       => 'required_with:ingredientes|integer',
            'ingredientes.*.cantidad_por_plato'=> 'required_with:ingredientes|numeric|min:0',
            'ingredientes.*.unidad'            => 'required_with:ingredientes|string|max:20',
        ]);

        $usuario = $request->user()?->email ?? 'sistema';

        DB::connection('compras')->transaction(function () use ($receta, $validated, $usuario) {
            $receta->update(array_merge(
                array_intersect_key($validated, array_flip(['nombre', 'descripcion', 'tipo', 'platos_semana', 'activa'])),
                ['aud_usuario' => $usuario]
            ));

            // Si se envian ingredientes, reemplazar todos
            if (array_key_exists('ingredientes', $validated)) {
                $receta->ingredientes()->delete();
                foreach ($validated['ingredientes'] as $ing) {
                    RecetaIngrediente::create([
                        'receta_id'          => $receta->id,
                        'producto_id'        => $ing['producto_id'],
                        'cantidad_por_plato' => $ing['cantidad_por_plato'],
                        'unidad'             => $ing['unidad'],
                        'aud_usuario'        => $usuario,
                    ]);
                }
            }
        });

        $receta->load('ingredientes.producto');
        return response()->json(['data' => $this->formatReceta($receta)]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // DELETE /api/compras/recetas/{id}
    // Desactiva la receta (soft-delete logico).
    // ──────────────────────────────────────────────────────────────────────
    public function destroy(int $id): JsonResponse
    {
        $receta = Receta::findOrFail($id);
        $receta->update(['activa' => false]);
        return response()->json(['message' => 'Receta desactivada.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/compras/recetas/calcular
    // Calcula totales de ingredientes para un conjunto de recetas + platos.
    //
    // Body: [{ receta_id: int, platos: int }, ...]
    // ──────────────────────────────────────────────────────────────────────
    public function calcular(Request $request): JsonResponse
    {
        $items = $request->validate([
            '*'                => 'array',
            '*.receta_id'      => 'required|integer',
            '*.platos'         => 'required|integer|min:0',
        ]);

        // Acumular {producto_id => totales} agrupando por codigo
        $acumulado = [];

        foreach ($items as $item) {
            $receta = Receta::with('ingredientes.producto')->find($item['receta_id']);
            if (!$receta) continue;

            foreach ($receta->ingredientes as $ing) {
                $prod = $ing->producto;
                if (!$prod) continue;

                $total = (float) $ing->cantidad_por_plato * (int) $item['platos'];
                $key   = $prod->codigo;

                if (!isset($acumulado[$key])) {
                    $acumulado[$key] = [
                        'producto_id'      => $prod->id,
                        'producto_codigo'  => $prod->codigo,
                        'producto_nombre'  => $prod->nombre,
                        'unidad'           => $ing->unidad,
                        'precio_unitario'  => (float) $prod->precio,
                        'cantidad_total'   => 0,
                    ];
                }
                $acumulado[$key]['cantidad_total'] += $total;
            }
        }

        return response()->json(['data' => array_values($acumulado)]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PATCH /api/compras/recetas/{id}/platos-sucursal
    // Establece (o actualiza) platos_semana de una receta para una sucursal.
    //
    // Body: { sucursal_id: int, platos_semana: int }
    // ──────────────────────────────────────────────────────────────────────
    public function setPlatosSucursal(Request $request, int $id): JsonResponse
    {
        $receta = Receta::findOrFail($id);

        $validated = $request->validate([
            'sucursal_id'   => 'required|integer|min:1',
            'platos_semana' => 'required|integer|min:0',
        ]);

        $usuario = $request->user()?->email ?? 'sistema';

        $cfg = RecetaSucursal::updateOrCreate(
            [
                'receta_id'   => $receta->id,
                'sucursal_id' => $validated['sucursal_id'],
            ],
            [
                'platos_semana' => $validated['platos_semana'],
                'activa'        => true,
                'aud_usuario'   => $usuario,
            ]
        );

        return response()->json([
            'data' => [
                'receta_id'     => $receta->id,
                'sucursal_id'   => $cfg->sucursal_id,
                'platos_semana' => $cfg->platos_semana,
            ],
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────
    private function formatReceta(Receta $r, ?int $sucursalId = null): array
    {
        return [
            'id'            => $r->id,
            'nombre'        => $r->nombre,
            'descripcion'   => $r->descripcion,
            'tipo'          => $r->tipo,
            'categoria'     => $r->tipo,   // alias frontend (recetasService usa "categoria")
            'platos_semana' => $r->platosParaSucursal($sucursalId),
            'activa'        => $r->activa,
            'ingredientes'  => $r->ingredientes->map(fn ($ing) => [
                'id'                 => $ing->id,
                'producto_id'        => $ing->producto_id,
                'producto_codigo'    => $ing->producto?->codigo,
                'producto_nombre'    => $ing->producto?->nombre,
                'cantidad_por_plato' => (float) $ing->cantidad_por_plato,
                'unidad'             => $ing->unidad,
            ])->values(),
        ];
    }
}
