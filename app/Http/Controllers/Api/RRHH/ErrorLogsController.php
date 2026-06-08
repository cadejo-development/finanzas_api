<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\RRHH\ErrorLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;

class ErrorLogsController extends Controller
{
    /**
     * GET /rrhh/admin/error-logs
     * Devuelve logs paginados con filtros.
     */
    public function index(Request $request): JsonResponse
    {
        $q = ErrorLog::query()->orderBy('created_at', 'desc');

        if ($request->filled('search')) {
            $s = $request->search;
            $q->where(function ($sub) use ($s) {
                $sub->where('mensaje',      'ilike', "%{$s}%")
                    ->orWhere('controlador', 'ilike', "%{$s}%")
                    ->orWhere('funcion',     'ilike', "%{$s}%")
                    ->orWhere('usuario_email','ilike', "%{$s}%")
                    ->orWhere('tipo_excepcion','ilike', "%{$s}%");
            });
        }

        if ($request->filled('severidad')) {
            $q->where('severidad', $request->severidad);
        }

        if ($request->filled('resuelto')) {
            $q->where('resuelto', filter_var($request->resuelto, FILTER_VALIDATE_BOOLEAN));
        }

        if ($request->filled('controlador')) {
            $q->where('controlador', $request->controlador);
        }

        if ($request->filled('desde')) {
            $q->whereDate('created_at', '>=', $request->desde);
        }

        if ($request->filled('hasta')) {
            $q->whereDate('created_at', '<=', $request->hasta);
        }

        $perPage = min((int) ($request->per_page ?? 20), 100);

        $logs = $q->paginate($perPage);

        return response()->json([
            'success' => true,
            'data'    => $logs->items(),
            'meta'    => [
                'total'        => $logs->total(),
                'per_page'     => $logs->perPage(),
                'current_page' => $logs->currentPage(),
                'last_page'    => $logs->lastPage(),
            ],
        ]);
    }

    /**
     * PATCH /rrhh/admin/error-logs/{id}/resolver
     * Marca un log como resuelto con notas opcionales.
     */
    public function resolver(Request $request, int $id): JsonResponse
    {
        $log = ErrorLog::findOrFail($id);
        $log->update([
            'resuelto'           => true,
            'notas_resolucion'   => $request->notas,
            'resuelto_at'        => now(),
            'resuelto_por'       => $request->user()?->email,
        ]);

        return response()->json(['success' => true]);
    }

    /**
     * DELETE /rrhh/admin/error-logs/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        ErrorLog::findOrFail($id)->delete();
        return response()->json(['success' => true]);
    }

    /**
     * PATCH /rrhh/admin/error-logs/resolver-todos
     * Marca como resueltos todos los logs pendientes del filtro actual.
     */
    public function resolverTodos(Request $request): JsonResponse
    {
        $query = ErrorLog::where('resuelto', false);
        if ($request->filled('desde'))    $query->whereDate('created_at', '>=', $request->desde);
        if ($request->filled('hasta'))    $query->whereDate('created_at', '<=', $request->hasta);
        if ($request->filled('severidad')) $query->where('severidad', $request->severidad);

        $count = $query->update([
            'resuelto'          => true,
            'resuelto_en'       => now(),
            'resuelto_por'      => Auth::user()?->email ?? 'sistema',
            'notas_resolucion'  => 'Resuelto en bloque',
        ]);
        return response()->json(['success' => true, 'resueltos' => $count]);
    }

    /**
     * DELETE /rrhh/admin/error-logs
     * Elimina logs según filtros (por defecto solo resueltos).
     */
    public function clear(Request $request): JsonResponse
    {
        $query = ErrorLog::query();
        if ($request->filled('resuelto')) $query->where('resuelto', filter_var($request->resuelto, FILTER_VALIDATE_BOOLEAN));
        if ($request->filled('desde'))    $query->whereDate('created_at', '>=', $request->desde);
        if ($request->filled('hasta'))    $query->whereDate('created_at', '<=', $request->hasta);
        $count = $query->delete();
        return response()->json(['success' => true, 'eliminados' => $count]);
    }

    /**
     * GET /rrhh/admin/error-logs/stats
     * Resumen rápido para badge del botón flotante.
     */
    public function stats(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data'    => [
                'total'         => ErrorLog::count(),
                'no_resueltos'  => ErrorLog::where('resuelto', false)->count(),
                'errores'       => ErrorLog::where('severidad', 'error')->where('resuelto', false)->count(),
                'warnings'      => ErrorLog::where('severidad', 'warning')->where('resuelto', false)->count(),
            ],
        ]);
    }
}
