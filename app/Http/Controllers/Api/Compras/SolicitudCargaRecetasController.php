<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Mail\Compras\SolicitudCargaNotificacion;
use App\Models\SolicitudCargaReceta;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SolicitudCargaRecetasController extends Controller
{
    // GET /api/compras/solicitudes-carga
    public function index(Request $request): JsonResponse
    {
        $q = SolicitudCargaReceta::orderByDesc('id');

        if ($estado = $request->query('estado')) {
            $q->where('estado', $estado);
        }

        $solicitudes = $q->paginate((int) $request->query('per_page', 20));

        return response()->json([
            'data' => $solicitudes->items(),
            'meta' => [
                'current_page' => $solicitudes->currentPage(),
                'last_page'    => $solicitudes->lastPage(),
                'total'        => $solicitudes->total(),
            ],
        ]);
    }

    // POST /api/compras/solicitudes-carga
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'receta_ids'      => 'required|array|min:1',
            'receta_ids.*'    => 'integer|min:1',
            'fecha_requerida' => 'required|date',
            'nota'            => 'nullable|string|max:1000',
        ]);

        $usuario = auth()->user();

        // Obtener nombres de las recetas seleccionadas
        $recetas = DB::connection('compras')
            ->table('recetas')
            ->whereIn('id', $validated['receta_ids'])
            ->select('id', 'nombre', 'tipo_receta')
            ->orderBy('nombre')
            ->get();

        $recetaNombres = $recetas->pluck('nombre')->toArray();

        $solicitud = SolicitudCargaReceta::create([
            'fecha_requerida'      => $validated['fecha_requerida'],
            'nota'                 => $validated['nota'] ?? null,
            'estado'               => 'pendiente',
            'solicitado_por'       => $usuario->email,
            'solicitado_por_nombre'=> $usuario->name,
            'receta_ids'           => $validated['receta_ids'],
            'receta_nombres'       => $recetaNombres,
            'total_recetas'        => count($validated['receta_ids']),
            'aud_usuario'          => $usuario->email,
        ]);

        $this->notificarAdminsCompras($solicitud, $recetaNombres);

        return response()->json(['data' => $solicitud], 201);
    }

    // PATCH /api/compras/solicitudes-carga/{id}
    public function update(Request $request, int $id): JsonResponse
    {
        $solicitud = SolicitudCargaReceta::findOrFail($id);

        $validated = $request->validate([
            'estado' => 'required|in:pendiente,completada',
        ]);

        $solicitud->update($validated);

        return response()->json(['data' => $solicitud->fresh()]);
    }

    private function notificarAdminsCompras(SolicitudCargaReceta $solicitud, array $recetaNombres): void
    {
        try {
            $admins = DB::connection('pgsql')
                ->table('users as u')
                ->join('role_user as ru', 'ru.user_id', '=', 'u.id')
                ->join('roles as r', 'r.id', '=', 'ru.role_id')
                ->where('r.codigo', 'admin_compras')
                ->where('u.activo', true)
                ->select('u.email', 'u.name')
                ->get();

            if ($admins->isEmpty()) return;

            $recetaIdsParam = implode(',', $solicitud->receta_ids);
            $baseUrl        = rtrim(config('app.frontend_compras_url', 'https://operacion.cervezacadejo.com'), '/');
            $linkUrl        = "{$baseUrl}/exportar-brilo";

            $fechaFormateada = \Carbon\Carbon::parse($solicitud->fecha_requerida)
                ->locale('es')
                ->isoFormat('D [de] MMMM [de] YYYY');

            $mailable = new SolicitudCargaNotificacion(
                solicitadoPorNombre: $solicitud->solicitado_por_nombre ?? $solicitud->solicitado_por,
                fechaRequerida:      $fechaFormateada,
                recetaNombres:       $recetaNombres,
                totalRecetas:        $solicitud->total_recetas,
                recetaIdsParam:      $recetaIdsParam,
                nota:                $solicitud->nota,
                linkUrl:             $linkUrl,
            );

            foreach ($admins as $admin) {
                Mail::to($admin->email)->send($mailable);
            }
        } catch (\Throwable $e) {
            Log::warning('Compras: Error notificando solicitud de carga', [
                'solicitud_id' => $solicitud->id,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
