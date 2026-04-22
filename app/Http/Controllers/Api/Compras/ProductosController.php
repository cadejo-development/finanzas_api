<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Categoria;
use App\Models\Producto;
use App\Models\Sucursal;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductosController extends Controller
{
    use \App\Traits\RecetaCostoTrait;
    /**
     * GET /api/compras/productos
     * Lista paginada de productos activos.
     *
     * Parámetros:
     *   - categoria   (string) : key de categoría — filtra por ella
     *   - prefijo     (string) : prefijo de key, ej: 'MR' → MR-01, MR-02...
     *   - search      (string) : búsqueda en nombre o código
     *   - sucursal_id (int)    : solo productos usados en recetas de esa sucursal
     *   - page        (int)    : página (default 1)
     *   - per_page    (int)    : registros por página (default 10, max 50)
     */
    public function index(Request $request): JsonResponse
    {
        // Si se solicita 'all=1', devolver todos sin paginar (solo para uso interno como CSV matching)
        if ($request->boolean('all')) {
            $query = Producto::with('categoria')
                ->where('activo', true)
                ->orderBy('nombre');

            if ($cat = $request->query('categoria')) {
                $query->whereHas('categoria', fn ($q) => $q->where('key', $cat));
            }
            if ($prefijo = $request->query('prefijo')) {
                $query->whereHas('categoria', fn ($q) => $q->where('key', 'ilike', $prefijo . '%'));
            }
            if ($search = $request->query('search')) {
                $query->where(fn ($q) => $q->where('nombre', 'ilike', "%{$search}%")->orWhere('codigo', 'ilike', "%{$search}%"));
            }

            $items = $query->get()->map(fn ($p) => [
                'id'     => $p->id,
                'codigo' => $p->codigo,
                'nombre' => $p->nombre,
                'unidad' => $p->unidad,
                'origen' => $p->origen ?? 'restaurante',
            ]);

            return response()->json(['data' => $items]);
        }

        $perPage = min((int) $request->query('per_page', 10), 200);

        $query = Producto::with('categoria')
            ->where('activo', true)
            ->orderBy('nombre');

        // Filtro por categoría exacta
        if ($cat = $request->query('categoria')) {
            $query->whereHas('categoria', fn ($q) => $q->where('key', $cat));
        }

        // Filtro por prefijo de categoría (ej: prefijo=MR → todas las MR-01, MR-02, ...)
        if ($prefijo = $request->query('prefijo')) {
            $query->whereHas('categoria', fn ($q) => $q->where('key', 'ilike', $prefijo . '%'));
        }

        // Filtro por sucursal: solo productos que son ingredientes de recetas activas de esa sucursal
        if ($sucursalId = $request->query('sucursal_id')) {
            $query->whereIn('id', function ($sub) use ($sucursalId) {
                $sub->select('ri.producto_id')
                    ->from('receta_ingredientes as ri')
                    ->join('receta_sucursal as rs', 'rs.receta_id', '=', 'ri.receta_id')
                    ->where('rs.sucursal_id', (int) $sucursalId)
                    ->where('rs.activa', true);
            });
        }

        // Filtro por origen: 'restaurante' | 'centro_produccion'
        if ($origen = $request->query('origen')) {
            $query->where('origen', $origen);
        }

        // Búsqueda por nombre o código
        if ($search = $request->query('search')) {
            $query->where(function ($q) use ($search) {
                $q->where('nombre', 'ilike', "%{$search}%")
                  ->orWhere('codigo', 'ilike', "%{$search}%");
            });
        }

        $paginado = $query->paginate($perPage);

        // Transformar para el frontend (campo categoria_key aplanado)
        $items = $paginado->getCollection()->map(function ($p) {
            $costo = (float) $p->costo;

            // Para productos con costo=0 que son sub-recetas, calcular desde ingredientes
            if ($costo === 0.0) {
                $receta = \App\Models\Receta::with([
                    'ingredientes.producto',
                    'ingredientes.subReceta.productoAsociado',
                    'ingredientes.subReceta.ingredientes.producto',
                ])->where('codigo_origen', $p->codigo)
                  ->where('tipo_receta', 'sub_receta')
                  ->where('activa', true)
                  ->first();

                if ($receta) {
                    $batchCosto  = $this->calcularBatchCostoDirecto($receta);
                    $rendimiento = (float) ($receta->rendimiento ?? 0);
                    $costo       = $rendimiento > 0 ? $batchCosto / $rendimiento : $batchCosto;
                    // Guardar para evitar recalcular en futuros listados
                    if ($costo > 0) {
                        $p->update(['costo' => round($costo, 4), 'aud_usuario' => 'sistema-sync']);
                    }
                }
            }

            return [
            'id'           => $p->id,
            'codigo'       => $p->codigo,
            'nombre'       => $p->nombre,
            'unidad'             => $p->unidad,
            'unidad_base'        => $p->unidad_base,
            'factor_conversion'  => $p->factor_conversion ? (float) $p->factor_conversion : null,
            'precio'          => (float) $p->precio,
            'costo'           => $costo,
            'precio_unitario' => $costo,  // costo para cálculos de recetas
            'activo'          => $p->activo,
            'categoria_id'    => $p->categoria_id,
            'categoria_key'    => $p->categoria?->key,
            'categoria_nombre' => $p->categoria?->nombre,
            'origen'           => $p->origen ?? 'restaurante',
            ];
        });

        return response()->json([
            'data'         => $items,
            'meta'         => [
                'current_page' => $paginado->currentPage(),
                'last_page'    => $paginado->lastPage(),
                'per_page'     => $paginado->perPage(),
                'total'        => $paginado->total(),
                'from'         => $paginado->firstItem(),
                'to'           => $paginado->lastItem(),
            ],
        ]);
    }

    /**
     * GET /api/compras/catalogos
     * Devuelve las categorías activas ordenadas.
     */
    public function catalogos(): JsonResponse
    {
        $cats = Categoria::where('activo', true)
            ->orderBy('orden')
            ->get(['id', 'key', 'nombre', 'orden']);

        return response()->json(['data' => $cats]);
    }

    /**
     * GET /api/compras/unidades
     * Devuelve las unidades de medida activas ordenadas.
     */
    public function unidades(): JsonResponse
    {
        $unidades = \DB::connection('compras')
            ->table('unidades_medida')
            ->where('activo', true)
            ->orderBy('orden')
            ->get(['id', 'codigo', 'nombre', 'descripcion']);

        return response()->json(['data' => $unidades]);
    }

    /**
     * GET /api/compras/productos/siguiente-codigo?categoria_id=X
     * Devuelve el siguiente código disponible para una categoría.
     * Formato: CCCC+YY+MM+NN
     *   CCCC = key de categoría sin guión (ej: MR-01 → MR01)
     *   YY   = año actual 2 dígitos
     *   MM   = mes actual 2 dígitos
     *   NN   = correlativo mensual 2 dígitos
     */
    public function siguienteCodigo(Request $request): JsonResponse
    {
        $request->validate([
            'categoria_id' => 'required|integer|exists:compras.categorias,id',
        ]);

        $categoria = Categoria::findOrFail($request->integer('categoria_id'));

        // CCCC: key sin guión (MR-01 → MR01)
        $cccc = str_replace('-', '', $categoria->key);
        $yy   = now()->format('y');  // 26
        $mm   = now()->format('m');  // 03

        $prefijoBusqueda = $cccc . $yy . $mm; // ej: MR012603

        // Solo códigos con formato CCCC+YY+MM+NN (2 dígitos finales)
        $pattern = '/^' . preg_quote($prefijoBusqueda, '/') . '(\d{2})$/';

        $maxNum = Producto::where('codigo', 'ilike', $prefijoBusqueda . '%')
            ->pluck('codigo')
            ->map(fn ($c) => preg_match($pattern, $c, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        $nn     = str_pad($maxNum + 1, 2, '0', STR_PAD_LEFT);
        $codigo = $prefijoBusqueda . $nn;

        return response()->json(['data' => ['codigo' => $codigo, 'prefijo' => $cccc]]);
    }

    /**
     * POST /api/compras/productos
     * Crea un nuevo producto.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'categoria_id'      => 'required|integer|exists:compras.categorias,id',
            'codigo'            => 'required|string|max:30|unique:compras.productos,codigo',
            'nombre'            => 'required|string|max:150',
            'unidad'            => 'required|string|exists:compras.unidades_medida,codigo',
            'unidad_base'       => 'nullable|string|max:20',
            'factor_conversion' => 'nullable|numeric|min:0.0001',
            'precio'            => 'nullable|numeric|min:0',
            'costo'             => 'required|numeric|min:0',
            'origen'            => 'nullable|in:restaurante,centro_produccion',
        ]);

        $data['activo']      = true;
        $data['origen']      = $data['origen'] ?? 'restaurante';
        $data['aud_usuario'] = $request->user()?->email;

        $producto = Producto::create($data);
        $producto->load('categoria');

        return response()->json(['data' => [
            'id'                => $producto->id,
            'codigo'            => $producto->codigo,
            'nombre'            => $producto->nombre,
            'unidad'            => $producto->unidad,
            'unidad_base'       => $producto->unidad_base,
            'factor_conversion' => $producto->factor_conversion ? (float) $producto->factor_conversion : null,
            'precio'            => (float) $producto->precio,
            'costo'             => (float) $producto->costo,
            'precio_unitario'   => (float) $producto->costo,
            'activo'            => $producto->activo,
            'categoria_id'      => $producto->categoria_id,
            'categoria_key'     => $producto->categoria?->key,
            'categoria_nombre'  => $producto->categoria?->nombre,
            'origen'            => $producto->origen ?? 'restaurante',
        ]], 201);
    }

    /**
     * PUT /api/compras/productos/{id}
     * Actualiza un producto existente.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $producto = Producto::where('activo', true)->findOrFail($id);

        $data = $request->validate([
            'categoria_id'      => 'sometimes|integer|exists:compras.categorias,id',
            'codigo'            => "sometimes|string|max:30|unique:compras.productos,codigo,{$id}",
            'nombre'            => 'sometimes|string|max:150',
            'unidad'            => 'sometimes|string|exists:compras.unidades_medida,codigo',
            'unidad_base'       => 'nullable|string|max:20',
            'factor_conversion' => 'nullable|numeric|min:0.0001',
            'precio'            => 'sometimes|numeric|min:0',
            'costo'             => 'sometimes|numeric|min:0',
            'origen'            => 'nullable|in:restaurante,centro_produccion',
        ]);

        if (isset($data['origen']) && $data['origen'] === null) {
            $data['origen'] = 'restaurante';
        }
        $data['aud_usuario']           = $request->user()?->email;
        $data['modificado_localmente'] = true;

        $producto->update($data);
        $producto->load('categoria');

        return response()->json(['data' => [
            'id'                => $producto->id,
            'codigo'            => $producto->codigo,
            'nombre'            => $producto->nombre,
            'unidad'            => $producto->unidad,
            'unidad_base'       => $producto->unidad_base,
            'factor_conversion' => $producto->factor_conversion ? (float) $producto->factor_conversion : null,
            'precio'            => (float) $producto->precio,
            'costo'             => (float) $producto->costo,
            'precio_unitario'   => (float) $producto->costo,
            'activo'            => $producto->activo,
            'categoria_id'      => $producto->categoria_id,
            'categoria_key'     => $producto->categoria?->key,
            'categoria_nombre'  => $producto->categoria?->nombre,
            'origen'            => $producto->origen ?? 'restaurante',
        ]]);
    }

    /**
     * DELETE /api/compras/productos/{id}
     * Desactiva un producto (soft-delete).
     */
    public function destroy(int $id): JsonResponse
    {
        $producto = Producto::where('activo', true)->findOrFail($id);
        $producto->update(['activo' => false]);

        return response()->json(['message' => 'Producto desactivado.']);
    }

    /**
     * GET /api/compras/sucursales
     * Devuelve las sucursales activas (desde pgsql).
     */
    public function sucursales(): JsonResponse
    {
        $sucursales = Sucursal::whereHas('tipoSucursal', fn($q) => $q->where('codigo', 'operativa'))
            ->where('id', '!=', 19) // excluir RES - CASA GUIROLA (duplicado de RESTAURANTE CASA GUIROLA)
            ->where(fn($q) => $q->where('activa', true)->orWhereNull('activa'))
            ->orderBy('nombre')
            ->get(['id', 'codigo', 'nombre']);

        return response()->json(['success' => true, 'data' => $sucursales]);
    }
}
