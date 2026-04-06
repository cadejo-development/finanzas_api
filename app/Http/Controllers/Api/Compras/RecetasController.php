<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\Receta;
use App\Models\RecetaIngrediente;
use App\Models\RecetaModificador;
use App\Models\RecetaSucursal;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Illuminate\Support\Facades\Storage;

class RecetasController extends Controller
{
    // ──────────────────────────────────────────────────────────────────────
    // GET /api/compras/recetas
    // Lista paginada de recetas (con ingredientes + producto).
    // Query opcional: sucursal_id, tipo_receta ('plato'|'sub_receta')
    // ──────────────────────────────────────────────────────────────────────
    public function index(Request $request): JsonResponse
    {
        $perPage    = min((int) $request->query('per_page', 20), 100);
        $sucursalId  = $request->query('sucursal_id')  ? (int) $request->query('sucursal_id') : null;
        // sucursal_ids: array de IDs para gerentes multi-sucursal (e.g. ?sucursal_ids[]=1&sucursal_ids[]=5)
        $sucursalIds = $request->query('sucursal_ids') ? array_map('intval', (array) $request->query('sucursal_ids')) : null;

        $query = Receta::with([
                'categoria',
                'ingredientes.producto',
                'ingredientes.subReceta.productoAsociado',
                'ingredientes.subReceta.ingredientes.producto',
                // Sub-sub-recetas: ingredientes de sub-recetas que son a su vez sub-recetas
                'ingredientes.subReceta.ingredientes.subReceta.productoAsociado',
                'ingredientes.subReceta.ingredientes.subReceta.ingredientes.producto',
            ])
            ->withCount(['modificadores as grupos_modificadores' => fn ($q) =>
                $q->select(DB::raw('COUNT(DISTINCT grupo_id_origen)'))
            ])
            ->where('activa', true)
            ->orderBy('nombre');

        // Filtrar solo recetas activas en esa sucursal + cargar su config
        if ($sucursalId !== null) {
            $query->whereHas('sucursalConfig', fn ($q) =>
                $q->where('sucursal_id', $sucursalId)->where('activa', true)
            );
            $query->with(['sucursalConfig' => fn ($q) => $q->where('sucursal_id', $sucursalId)]);
        } elseif (!empty($sucursalIds)) {
            // Gerente multi-sucursal: mostrar recetas activas en CUALQUIERA de sus sucursales
            $query->whereHas('sucursalConfig', fn ($q) =>
                $q->whereIn('sucursal_id', $sucursalIds)->where('activa', true)
            );
            $query->with(['sucursalConfig' => fn ($q) => $q->whereIn('sucursal_id', $sucursalIds)]);
        }

        // Filtro por categoria_id (nuevo) o tipo texto (legado)
        if ($categoriaId = $request->query('categoria_id')) {
            $query->where('categoria_id', (int) $categoriaId);
        } elseif ($tipo = $request->query('tipo')) {
            $query->where('tipo', $tipo);
        }

        // Filtro por tipo_receta: 'plato' | 'sub_receta'
        // Incluye también registros con tipo (categoría) que contenga 'Sub-Receta'
        // para compatibilidad con datos migrados antes del campo tipo_receta.
        if ($tipoReceta = $request->query('tipo_receta')) {
            $query->where(function ($q) use ($tipoReceta) {
                $q->where('tipo_receta', $tipoReceta);
                if ($tipoReceta === 'sub_receta') {
                    $q->orWhereRaw("lower(tipo) LIKE '%sub%receta%'");
                }
            });
            // En el catálogo de sub-recetas solo mostrar las de categoría "Platos Sub-Recetas".
            // Las modifier sub-recetas (agua dura, bebidas, etc.) son ingredientes internos
            // y no deben aparecer en el listado del catálogo.
            if ($tipoReceta === 'sub_receta') {
                $query->where(function ($q) {
                    $q->whereRaw("lower(coalesce(tipo,'')) LIKE '%sub%receta%'")
                      ->orWhereHas('categoria', fn ($sq) => $sq->whereRaw("lower(nombre) LIKE '%sub%receta%'"));
                });
            }
        } else {
            // Sin filtro de tipo_receta: excluir los marcados como sub_receta,
            // y también los de categoría "Platos Sub-Recetas" para que no se mezclen.
            $query->where(function ($q) {
                $q->where('tipo_receta', '!=', 'sub_receta')
                  ->orWhereNull('tipo_receta');
            })->whereRaw("lower(coalesce(tipo,'')) NOT LIKE '%sub%receta%'");
        }

        // Excluir recetas cuya categoría está marcada como inactiva
        // (cervezas, aguas, cristalería, envases, etc. no deben aparecer en el catálogo)
        $query->where(function ($q) {
            $q->whereNull('categoria_id')
              ->orWhereHas('categoria', fn ($sq) => $sq->where('activa', true));
        });

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

        $receta = Receta::with([
            'categoria',
            'ingredientes.producto',
            'ingredientes.subReceta.productoAsociado',
            'ingredientes.subReceta.ingredientes.producto',
            'ingredientes.subReceta.ingredientes.subReceta.productoAsociado',
            'ingredientes.subReceta.ingredientes.subReceta.ingredientes.producto',
            'modificadores.producto',
            'sucursalConfig',  // Siempre cargar todas las sucursales asignadas
        ])->findOrFail($id);

        return response()->json(['data' => $this->formatReceta($receta, $sucursalId, true)]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/compras/recetas/{id}/pdf
    // Body JSON opcional: { foto_plato_b64: "data:image/...", foto_plateria_b64: "..." }
    // El browser convierte las fotos a base64 antes de enviarlas (evita que
    // App Runner intente descargar desde S3, lo cual falla por red).
    // ──────────────────────────────────────────────────────────────────────
    public function pdf(int $id, Request $request): Response
    {
        $receta = Receta::with([
            'categoria',
            'sucursalConfig',
            'ingredientes.producto',
            'ingredientes.subReceta.productoAsociado',
            'ingredientes.subReceta.ingredientes.producto',
            'ingredientes.subReceta.ingredientes.subReceta.productoAsociado',
            'ingredientes.subReceta.ingredientes.subReceta.ingredientes.producto',
            'modificadores.producto',
        ])->findOrFail($id);

        $data = $this->formatReceta($receta, null, true);

        $costoIngredientes = collect($data['ingredientes'])->sum(
            fn ($i) => (float) $i['precio_unitario'] * (float) $i['cantidad_por_plato']
        );
        $data['costo_total'] = $costoIngredientes;

        // Fotos enviadas como base64 desde el browser
        $fotoPlato      = $request->input('foto_plato_b64');
        $fotoPlateria   = $request->input('foto_plateria_b64');
        $sucursalNombre = $request->input('sucursal_nombre', 'Cadejo Brewing Company');

        // Obtener nombres de todas las sucursales activas de esta receta
        $sucursalIds = $receta->sucursalConfig
            ->where('activa', true)
            ->pluck('sucursal_id')
            ->filter()
            ->unique()
            ->values();
        $sucursalesNombres = $sucursalIds->isNotEmpty()
            ? \App\Models\Sucursal::whereIn('id', $sucursalIds)->pluck('nombre')->join(' / ')
            : null;

        try {
            $pdf = Pdf::loadView('pdf.receta', [
                'receta'          => $data,
                'costo_total'     => $costoIngredientes,
                'foto_plato'      => $fotoPlato,
                'foto_plateria'   => $fotoPlateria,
                'sucursal_nombre'   => $sucursalNombre,
                'sucursales_nombres' => $sucursalesNombres,
            ])->setPaper('letter', 'portrait');

            $nombre = preg_replace('/[^A-Za-z0-9_\-]/', '_', $receta->nombre);
            return $pdf->download("receta_{$nombre}.pdf");
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'file'    => $e->getFile() . ':' . $e->getLine(),
                'trace'   => collect(explode("\n", $e->getTraceAsString()))->take(10)->implode("\n"),
            ], 500);
        }
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/compras/recetas
    // ──────────────────────────────────────────────────────────────────────
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'nombre'              => 'required|string|max:150',
            'descripcion'         => 'nullable|string',
            'instrucciones'       => 'nullable|string',
            'tipo'                => 'nullable|string|max:80',
            'categoria_id'        => 'nullable|integer|exists:compras.receta_categorias,id',
            'tipo_receta'         => 'nullable|in:plato,sub_receta',
            'platos_semana'       => 'required|integer|min:0',
            'precio'              => 'nullable|numeric|min:0',
            'rendimiento'         => 'nullable|numeric|min:0',
            'rendimiento_unidad'  => 'nullable|string|max:20',
            'foto_plato'          => 'nullable|string|max:500',
            'foto_plateria'       => 'nullable|string|max:500',
            'sucursal_ids'        => 'nullable|array',
            'sucursal_ids.*'      => 'integer|min:1',
            'ingredientes'        => 'array',
            'ingredientes.*.producto_id'       => 'nullable|integer',
            'ingredientes.*.sub_receta_id'     => 'nullable|integer',
            'ingredientes.*.cantidad_por_plato'=> 'required|numeric|min:0',
            'ingredientes.*.unidad'            => 'required|string|max:20',
        ]);

        $tipoReceta = $validated['tipo_receta'] ?? 'plato';
        $usuario    = $request->user()?->email ?? 'sistema';


        $receta = DB::connection('compras')->transaction(function () use ($validated, $tipoReceta, $usuario): Receta {
            $receta = Receta::create([
                'nombre'        => $validated['nombre'],
                'descripcion'   => $validated['descripcion'] ?? null,
                'instrucciones' => $validated['instrucciones'] ?? null,
                'tipo'          => $validated['tipo'] ?? null,
                'categoria_id'  => $validated['categoria_id'] ?? null,
                'tipo_receta'        => $tipoReceta,
                'platos_semana'      => $validated['platos_semana'],
                'precio'             => $validated['precio'] ?? null,
                'rendimiento'        => $validated['rendimiento'] ?? null,
                'rendimiento_unidad' => $validated['rendimiento_unidad'] ?? null,
                'foto_plato'         => $validated['foto_plato'] ?? null,
                'foto_plateria'      => $validated['foto_plateria'] ?? null,
                'activa'        => true,
                'aud_usuario'   => $usuario,
            ]);

            foreach ($validated['ingredientes'] ?? [] as $ing) {
                RecetaIngrediente::create([
                    'receta_id'          => $receta->id,
                    'producto_id'        => $ing['producto_id'] ?? null,
                    'sub_receta_id'      => $ing['sub_receta_id'] ?? null,
                    'cantidad_por_plato' => $ing['cantidad_por_plato'],
                    'unidad'             => $ing['unidad'],
                    'aud_usuario'        => $usuario,
                ]);
            }

            foreach ($validated['sucursal_ids'] ?? [] as $sucId) {
                RecetaSucursal::create([
                    'receta_id'     => $receta->id,
                    'sucursal_id'   => $sucId,
                    'platos_semana' => $validated['platos_semana'],
                    'activa'        => true,
                    'aud_usuario'   => $usuario,
                ]);
            }

            return $receta;
        });

        $receta->load(['ingredientes.producto', 'ingredientes.subReceta', 'sucursalConfig']);
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
            'instrucciones'       => 'nullable|string',
            'tipo'                => 'nullable|string|max:80',
            'categoria_id'        => 'nullable|integer|exists:compras.receta_categorias,id',
            'tipo_receta'         => 'nullable|in:plato,sub_receta',
            'platos_semana'       => 'sometimes|integer|min:0',
            'precio'              => 'nullable|numeric|min:0',
            'rendimiento'         => 'nullable|numeric|min:0',
            'rendimiento_unidad'  => 'nullable|string|max:20',
            'activa'              => 'sometimes|boolean',
            'foto_plato'          => 'nullable|string|max:500',
            'foto_plateria'       => 'nullable|string|max:500',
            'sucursal_ids'        => 'nullable|array',
            'sucursal_ids.*'      => 'integer|min:1',
            'ingredientes'        => 'sometimes|array',
            'ingredientes.*.producto_id'       => 'nullable|integer',
            'ingredientes.*.sub_receta_id'     => 'nullable|integer',
            'ingredientes.*.cantidad_por_plato'=> 'required_with:ingredientes|numeric|min:0',
            'ingredientes.*.unidad'            => 'required_with:ingredientes|string|max:20',
            'modificadores'       => 'sometimes|array',
            'modificadores.*.grupo_nombre'     => 'required_with:modificadores|string|max:100',
            'modificadores.*.grupo_codigo'     => 'nullable|string|max:50',
            'modificadores.*.opciones'         => 'array',
            'modificadores.*.opciones.*.opcion_nombre' => 'required|string|max:100',
            'modificadores.*.opciones.*.producto_id'   => 'nullable|integer',
            'modificadores.*.opciones.*.cantidad'      => 'nullable|numeric',
            'modificadores.*.opciones.*.unidad'        => 'nullable|string|max:20',
        ]);

        $tipoReceta = $validated['tipo_receta'] ?? $receta->tipo_receta;
        $usuario    = $request->user()?->email ?? 'sistema';


        DB::connection('compras')->transaction(function () use ($receta, $validated, $usuario) {
            $campos = array_intersect_key($validated, array_flip([
                'nombre', 'descripcion', 'instrucciones', 'tipo', 'categoria_id',
                'tipo_receta', 'platos_semana', 'precio', 'rendimiento', 'rendimiento_unidad',
                'activa', 'foto_plato', 'foto_plateria',
            ]));
            $receta->update(array_merge($campos, [
                'aud_usuario'           => $usuario,
                'modificado_localmente' => true,
            ]));

            // Reemplazar ingredientes si se envian
            if (array_key_exists('ingredientes', $validated)) {
                $receta->ingredientes()->delete();
                foreach ($validated['ingredientes'] as $ing) {
                    RecetaIngrediente::create([
                        'receta_id'          => $receta->id,
                        'producto_id'        => $ing['producto_id'] ?? null,
                        'sub_receta_id'      => $ing['sub_receta_id'] ?? null,
                        'cantidad_por_plato' => $ing['cantidad_por_plato'],
                        'unidad'             => $ing['unidad'],
                        'aud_usuario'        => $usuario,
                    ]);
                }
            }

            // Sincronizar sucursales si se envian
            if (array_key_exists('sucursal_ids', $validated)) {
                $nuevasIds = $validated['sucursal_ids'] ?? [];
                // Desactivar las que ya no están en la lista
                RecetaSucursal::where('receta_id', $receta->id)
                    ->whereNotIn('sucursal_id', $nuevasIds)
                    ->update(['activa' => false]);
                // Upsert las nuevas/existentes
                foreach ($nuevasIds as $sucId) {
                    RecetaSucursal::updateOrCreate(
                        ['receta_id' => $receta->id, 'sucursal_id' => $sucId],
                        ['activa' => true, 'aud_usuario' => $usuario]
                    );
                }
            }

            // Reemplazar modificadores si se envian
            if (array_key_exists('modificadores', $validated)) {
                $receta->modificadores()->delete();
                $grupoId = DB::connection('compras')->table('receta_modificadores')->max('grupo_id_origen') ?? 0;
                foreach ($validated['modificadores'] as $grupo) {
                    $grupoId++;
                    foreach ($grupo['opciones'] ?? [] as $opcion) {
                        RecetaModificador::create([
                            'receta_id'      => $receta->id,
                            'grupo_id_origen'=> $grupoId,
                            'grupo_codigo'   => $grupo['grupo_codigo'] ?? strtoupper(str_replace(' ', '_', $grupo['grupo_nombre'])),
                            'grupo_nombre'   => $grupo['grupo_nombre'],
                            'opcion_nombre'  => $opcion['opcion_nombre'],
                            'producto_id'    => $opcion['producto_id'] ?? null,
                            'cantidad'       => $opcion['cantidad'] ?? 0,
                            'unidad'         => $opcion['unidad'] ?? '',
                            'aud_usuario'    => $usuario,
                        ]);
                    }
                }
            }
        });

        $receta->load(['ingredientes.producto', 'ingredientes.subReceta', 'modificadores', 'sucursalConfig']);
        return response()->json(['data' => $this->formatReceta($receta, null, true)]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // DELETE /api/compras/recetas/{id}  — Inactiva la receta (soft-delete).
    // Body opcional: { sucursal_ids: [1, 2] } → inactiva solo en esas sucursales.
    // Sin sucursal_ids → inactiva globalmente (receta + todas sus sucursales).
    // ──────────────────────────────────────────────────────────────────────
    public function destroy(Request $request, int $id): JsonResponse
    {
        $receta     = Receta::findOrFail($id);
        $sucursalIds = $request->input('sucursal_ids');

        if (!empty($sucursalIds)) {
            $usuario = $request->user()?->email ?? 'sistema';
            foreach ($sucursalIds as $sucId) {
                RecetaSucursal::updateOrCreate(
                    ['receta_id' => $id, 'sucursal_id' => (int) $sucId],
                    ['activa' => false, 'platos_semana' => 0, 'aud_usuario' => $usuario]
                );
            }
            return response()->json(['message' => 'Receta inactivada en las sucursales seleccionadas.']);
        }

        $receta->update(['activa' => false]);
        RecetaSucursal::where('receta_id', $id)->update(['activa' => false]);
        return response()->json(['message' => 'Receta inactivada globalmente.']);
    }

    // ──────────────────────────────────────────────────────────────────────
    // GET /api/compras/recetas/tipos  (deprecated → usar /receta-categorias)
    // Mantenido por compatibilidad. Devuelve categorías activas de la DB.
    // ──────────────────────────────────────────────────────────────────────
    public function tipos(Request $request): JsonResponse
    {
        // Retornar las categorías reales del catálogo
        $categorias = \App\Models\RecetaCategoria::where('activa', true)
            ->orderBy('nombre')
            ->get(['id', 'nombre']);

        return response()->json(['data' => $categorias]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // POST /api/compras/recetas/calcular
    // ──────────────────────────────────────────────────────────────────────
    public function calcular(Request $request): JsonResponse
    {
        $items = $request->validate([
            '*'                => 'array',
            '*.receta_id'      => 'required|integer',
            '*.platos'         => 'required|integer|min:0',
        ]);

        $acumulado = [];

        foreach ($items as $item) {
            $receta = Receta::with(['ingredientes.producto', 'ingredientes.subReceta'])->find($item['receta_id']);
            if (!$receta) continue;

            foreach ($receta->ingredientes as $ing) {
                $total = (float) $ing->cantidad_por_plato * (int) $item['platos'];

                if ($ing->producto_id && $ing->producto) {
                    $prod = $ing->producto;
                    $key  = $prod->codigo;
                    if (!isset($acumulado[$key])) {
                        $acumulado[$key] = [
                            'producto_id'     => $prod->id,
                            'producto_codigo' => $prod->codigo,
                            'producto_nombre' => $prod->nombre,
                            'unidad'          => $ing->unidad,
                            'precio_unitario' => (float) $prod->costo,
                            'cantidad_total'  => 0,
                        ];
                    }
                    $acumulado[$key]['cantidad_total'] += $total;
                }
            }
        }

        return response()->json(['data' => array_values($acumulado)]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // PATCH /api/compras/recetas/{id}/platos-sucursal
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
            ['receta_id' => $receta->id, 'sucursal_id' => $validated['sucursal_id']],
            ['platos_semana' => $validated['platos_semana'], 'activa' => true, 'aud_usuario' => $usuario]
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
    // POST /api/compras/upload
    // Sube una foto de receta (foto_plato o foto_plateria).
    // Body: multipart/form-data con campo 'foto' (imagen)
    // Retorna: { url: '...' }
    // ──────────────────────────────────────────────────────────────────────
    // ──────────────────────────────────────────────────────────────────────
    // GET /api/compras/upload/presign?ext=jpg&mime=image/jpeg
    // Genera una URL pre-firmada de S3 para que el browser suba directamente.
    // No hace ninguna llamada de red a S3 — solo firma localmente.
    // Retorna: { presigned_url, public_url }
    // ──────────────────────────────────────────────────────────────────────
    public function presignUpload(Request $request): JsonResponse
    {
        $ext      = preg_replace('/[^a-zA-Z0-9]/', '', $request->query('ext', 'jpg'));
        $mime     = $request->query('mime', 'image/jpeg');
        $filename = uniqid('receta_', true) . '.' . $ext;
        $key      = 'recetas/fotos/' . $filename;

        $bucket = config('filesystems.disks.s3.bucket');
        $region = config('filesystems.disks.s3.region');

        try {
            $client = new \Aws\S3\S3Client([
                'region'      => $region,
                'version'     => 'latest',
                'credentials' => [
                    'key'    => config('filesystems.disks.s3.key'),
                    'secret' => config('filesystems.disks.s3.secret'),
                ],
            ]);

            $cmd = $client->getCommand('PutObject', [
                'Bucket'      => $bucket,
                'Key'         => $key,
                'ContentType' => $mime,
            ]);

            $presignedUrl = (string) $client->createPresignedRequest($cmd, '+15 minutes')->getUri();
        } catch (\Throwable $e) {
            return response()->json(['message' => $e->getMessage()], 500);
        }

        $publicUrl = "https://{$bucket}.s3.{$region}.amazonaws.com/{$key}";

        return response()->json([
            'presigned_url' => $presignedUrl,
            'public_url'    => $publicUrl,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────────
    private function formatReceta(Receta $r, ?int $sucursalId = null, bool $withModificadores = false): array
    {
        $data = [
            'id'            => $r->id,
            'nombre'        => $r->nombre,
            'codigo_origen' => $r->codigo_origen,
            'descripcion'   => $r->descripcion,
            'instrucciones' => $r->instrucciones,
            'tipo'           => $r->tipo,
            'categoria_id'   => $r->categoria_id,
            'categoria'      => $r->categoria?->nombre ?? $r->tipo,
            'tipo_receta'    => $r->tipo_receta ?? 'plato',
            'precio'             => (float) ($r->precio ?? 0),
            'platos_semana'      => $r->platosParaSucursal($sucursalId),
            'rendimiento'        => $r->rendimiento !== null ? (float) $r->rendimiento : null,
            'rendimiento_unidad' => $r->rendimiento_unidad,
            'activa'               => $r->activa,
            'foto_plato'    => $this->presignS3Url($r->foto_plato),
            'foto_plateria' => $this->presignS3Url($r->foto_plateria),
            'grupos_modificadores' => (int) ($r->grupos_modificadores ?? 0),
            'sucursales'    => $r->relationLoaded('sucursalConfig')
                ? $r->sucursalConfig->map(fn ($s) => [
                    'sucursal_id'   => $s->sucursal_id,
                    'platos_semana' => $s->platos_semana,
                    'activa'        => $s->activa,
                ])->values()
                : [],
            'ingredientes'  => $r->ingredientes->map(fn ($ing) => [
                'id'                 => $ing->id,
                'producto_id'        => $ing->producto_id,
                'sub_receta_id'      => $ing->sub_receta_id,
                'producto_codigo'    => $ing->producto?->codigo,
                'producto_nombre'    => $ing->producto?->nombre ?? $ing->subReceta?->nombre,
                'es_sub_receta'      => !is_null($ing->sub_receta_id),
                'cantidad_por_plato' => (float) $ing->cantidad_por_plato,
                'unidad'                 => $ing->unidad,
                'prod_unidad'            => $ing->producto?->unidad,
                'sub_rendimiento'        => $ing->subReceta ? ((float) ($ing->subReceta->rendimiento ?? 0) ?: null) : null,
                'sub_rendimiento_unidad' => $ing->subReceta?->rendimiento_unidad,
                'precio_unitario'    => $ing->sub_receta_id
                    ? $this->calcularCostoSubReceta($ing->subReceta, $ing->unidad)
                    : $this->convertirCosto(
                        (float) ($ing->producto?->costo ?? 0),
                        strtolower(trim($ing->producto?->unidad ?? '')),
                        strtolower(trim($ing->unidad ?? ''))
                      ),
            ])->values(),
        ];

        if ($withModificadores && $r->relationLoaded('modificadores')) {
            $grupos = [];
            foreach ($r->modificadores as $mod) {
                $key = $mod->grupo_id_origen;
                if (!isset($grupos[$key])) {
                    $grupos[$key] = [
                        'grupo_id'     => $mod->grupo_id_origen,
                        'grupo_codigo' => $mod->grupo_codigo,
                        'grupo_nombre' => $mod->grupo_nombre,
                        'opciones'     => [],
                        'costo_grupo'  => 0.0,
                    ];
                }
                $costoUnit = $this->convertirCosto(
                    (float) ($mod->producto?->costo ?? 0),
                    strtolower(trim($mod->producto?->unidad ?? '')),
                    strtolower(trim($mod->unidad ?? ''))
                );
                $costoOp = $costoUnit * (float) ($mod->cantidad ?? 0);
                $grupos[$key]['opciones'][] = [
                    'nombre'      => $mod->opcion_nombre,
                    'producto_id' => $mod->producto_id,
                    'cantidad'    => $mod->cantidad,
                    'unidad'      => $mod->unidad,
                    'costo'       => $costoUnit,
                    'costo_total' => round($costoOp, 6),
                ];
                $grupos[$key]['costo_grupo'] += $costoOp;
            }
            // Round costo_grupo
            foreach ($grupos as &$g) {
                $g['costo_grupo'] = round($g['costo_grupo'], 4);
            }
            $data['modificadores'] = array_values($grupos);
        }

        return $data;
    }

    private function calcularCostoSubReceta(?Receta $sub, ?string $unidadReceta = null, int $depth = 0): float
    {
        if (!$sub || $depth > 5) return 0.0;

        // Si el producto asociado tiene costo pre-almacenado, usarlo directamente.
        $prod = $sub->productoAsociado ?? null;
        if (!$prod && $sub->codigo_origen) {
            $prod = \App\Models\Producto::where('codigo', $sub->codigo_origen)->first();
        }
        if ($prod && (float) $prod->costo > 0) {
            return $this->convertirCosto(
                (float) $prod->costo,
                strtolower(trim($prod->unidad ?? '')),
                strtolower(trim($unidadReceta ?? ''))
            );
        }

        // Cargar ingredientes incluyendo sub-sub-recetas si no están cargados.
        if (!$sub->relationLoaded('ingredientes')) {
            $sub->load([
                'ingredientes.producto',
                'ingredientes.subReceta.productoAsociado',
                'ingredientes.subReceta.ingredientes.producto',
            ]);
        }

        $batchCosto = (float) $sub->ingredientes->sum(function ($si) use ($depth) {
            // Ingrediente es a su vez una sub-receta → calcular recursivamente.
            if ($si->sub_receta_id && $si->subReceta) {
                return (float) $si->cantidad_por_plato
                    * $this->calcularCostoSubReceta($si->subReceta, $si->unidad, $depth + 1);
            }
            $costo    = (float) ($si->producto?->costo ?? 0);
            $prodUnit = strtolower(trim($si->producto?->unidad ?? ''));
            $ingrUnit = strtolower(trim($si->unidad ?? ''));
            return (float) $si->cantidad_por_plato * $this->convertirCosto($costo, $prodUnit, $ingrUnit);
        });

        // Si la sub-receta tiene rendimiento definido: es el denominador explícito del batch.
        // Ej: rinde 10 porciones → costo/porcion = batch/10; padre usa 1 porcion → $batch/10.
        $rendimiento = (float) ($sub->rendimiento ?? 0);
        $rendUnidad  = strtolower(trim($sub->rendimiento_unidad ?? ''));
        if ($rendimiento > 0 && $rendUnidad) {
            $costePorUnidad = $batchCosto / $rendimiento;
            $targetUnit     = strtolower(trim($unidadReceta ?? ''));
            if (!$targetUnit || $targetUnit === $rendUnidad) {
                return $costePorUnidad;
            }
            return $this->convertirCosto($costePorUnidad, $rendUnidad, $targetUnit);
        }

        // Sin rendimiento explícito → batch = 1 unidad del producto (ej: 1 lb).
        // Para unidades desconocidas ('tanda', 'u', 'porcion', etc.) se trata como 'lb',
        // ya que SS usa mxprCantidad en libras internamente y el batch implícitamente
        // representa el costo por 1 lb de producción.
        $subUnit = strtolower(trim($prod?->unidad ?? ''));
        if ($subUnit && $unidadReceta) {
            $knownUnits = ['lb', 'oz', 'g', 'kg', 'lt', 'ml', 'oz fl', 'galon'];
            $effectiveUnit = in_array($subUnit, $knownUnits, true) ? $subUnit : 'lb';
            $batchCosto = $this->convertirCosto($batchCosto, $effectiveUnit, strtolower(trim($unidadReceta)));
        }

        return $batchCosto;
    }

    /**
     * Convierte una URL de S3 a una presigned GET URL válida por 2 horas.
     * La generación es puramente local (HMAC-SHA256), sin llamadas de red a S3.
     * Si la URL no es de nuestro bucket, la devuelve sin cambios.
     */
    private function presignS3Url(?string $url): ?string
    {
        if (!$url) return null;

        $bucket = config('filesystems.disks.s3.bucket');
        $region = config('filesystems.disks.s3.region');
        $prefix = "https://{$bucket}.s3.{$region}.amazonaws.com/";

        if (!str_starts_with($url, $prefix)) return $url;

        $key = substr($url, strlen($prefix));

        try {
            static $s3Client = null;
            if (!$s3Client) {
                $s3Client = new \Aws\S3\S3Client([
                    'region'      => $region,
                    'version'     => 'latest',
                    'credentials' => [
                        'key'    => config('filesystems.disks.s3.key'),
                        'secret' => config('filesystems.disks.s3.secret'),
                    ],
                ]);
            }
            $cmd = $s3Client->getCommand('GetObject', [
                'Bucket' => $bucket,
                'Key'    => $key,
            ]);
            return (string) $s3Client->createPresignedRequest($cmd, '+2 hours')->getUri();
        } catch (\Throwable $e) {
            return $url; // Si falla, devolver la URL original
        }
    }

    /**
     * Convierte el costo almacenado (por unidad de compra) a la unidad usada en la receta.
     * Ej: costo en $/lb → $/oz multiplica por (1/16).
     */
    private function convertirCosto(float $costo, string $desdePorUnidad, string $haciaUnidad): float
    {
        if ($costo === 0.0 || $desdePorUnidad === $haciaUnidad) return $costo;

        // Tabla de conversión: factor[FROM][TO] = # de unidades FROM que caben en 1 unidad TO
        // costo_TO = costo_FROM × factor
        $conv = [
            // Masa / peso
            'lb'    => ['oz' => 1/16,       'g'    => 1/453.592,  'kg'    => 1/0.453592, 'lb'    => 1],
            'kg'    => ['g'  => 1/1000,     'oz'   => 1/35.274,   'lb'    => 1/2.20462,  'kg'    => 1],
            'oz'    => ['lb' => 16,         'g'    => 28.3495,    'oz'    => 1],
            'g'     => ['lb' => 453.592,    'kg'   => 1000,       'oz'    => 28.3495,     'g'     => 1],
            // Volumen
            'lt'    => ['ml' => 1/1000,     'oz fl'=> 1/33.814,   'galon' => 3.78541,    'lt'    => 1],
            'galon' => ['oz fl' => 1/128,   'lt'   => 1/3.78541,  'ml'    => 1/3785.41,  'galon' => 1],
            'oz fl' => ['galon' => 128,     'lt'   => 33.814,     'ml'    => 33.814/1000, 'oz fl' => 1],
            'ml'    => ['lt' => 1000,       'oz fl'=> 1000/33.814,'galon' => 3785.41,    'ml'    => 1],
        ];

        $factor = $conv[$desdePorUnidad][$haciaUnidad] ?? null;
        return $factor !== null ? $costo * $factor : $costo;
    }
}
