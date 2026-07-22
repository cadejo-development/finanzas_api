<?php

namespace App\Http\Controllers\Api\RRHH;

use App\Models\PlantillaTurno;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PlantillasTurnoController extends RRHHBaseController
{
    use \App\Http\Controllers\Api\RRHH\Traits\RRHHCapturesExceptions;

    /**
     * GET /rrhh/horarios/plantillas?sucursal_id=N
     * Devuelve plantillas globales + las de la sucursal indicada.
     */
    public function index(Request $request): JsonResponse
    {
        $sucursalId = $request->query('sucursal_id');

        $query = PlantillaTurno::where('activo', true)->orderBy('hora_inicio');

        if ($sucursalId) {
            $query->where(fn($q) =>
                $q->whereNull('sucursal_id')->orWhere('sucursal_id', (int) $sucursalId)
            );
        }

        return response()->json(['data' => $query->get()]);
    }

    /**
     * POST /rrhh/horarios/plantillas
     * Body: { nombre, hora_inicio, hora_fin, sucursal_id? }
     */
    public function store(Request $request): JsonResponse
    {
        return $this->captureAndRespond($request, function () use ($request) {
            if (!$this->puedeAdministrar()) {
                return response()->json(['message' => 'Sin permisos para crear plantillas.'], 403);
            }

            $validated = $request->validate([
                'nombre'      => 'required|string|max:100',
                'hora_inicio' => 'required|date_format:H:i',
                'hora_fin'    => 'required|date_format:H:i',
                'sucursal_id' => 'nullable|integer',
            ]);

            // Jefes solo pueden crear plantillas para sus sucursales
            if (!$this->esAdminRrhh() && !$this->esAnalistaRrhh() && $validated['sucursal_id'] ?? null) {
                $sucs = $this->getSucursalesGestionadas();
                if (!in_array((int) $validated['sucursal_id'], $sucs)) {
                    return response()->json(['message' => 'No gestiona esa sucursal.'], 403);
                }
            }

            $plantilla = PlantillaTurno::create([
                'sucursal_id' => $validated['sucursal_id'] ?? null,
                'nombre'      => trim($validated['nombre']),
                'hora_inicio' => $validated['hora_inicio'],
                'hora_fin'    => $validated['hora_fin'],
                'activo'      => true,
                'aud_usuario' => Auth::user()?->email ?? 'sistema',
            ]);

            return response()->json(['data' => $plantilla], 201);
        });
    }

    /**
     * DELETE /rrhh/horarios/plantillas/{id}
     */
    public function destroy(int $id): JsonResponse
    {
        return $this->captureAndRespond(request(), function () use ($id) {
            if (!$this->puedeAdministrar()) {
                return response()->json(['message' => 'Sin permisos.'], 403);
            }

            $plantilla = PlantillaTurno::findOrFail($id);

            if (!$this->esAdminRrhh() && !$this->esAnalistaRrhh() && $plantilla->sucursal_id) {
                $sucs = $this->getSucursalesGestionadas();
                if (!in_array((int) $plantilla->sucursal_id, $sucs)) {
                    return response()->json(['message' => 'No gestiona esa sucursal.'], 403);
                }
            }

            $plantilla->delete();

            return response()->json(['success' => true]);
        });
    }

    private function puedeAdministrar(): bool
    {
        if ($this->esAdminRrhh() || $this->esAnalistaRrhh()) return true;
        return !empty($this->getSubordinadosIds());
    }

    private function getSucursalesGestionadas(): array
    {
        return DB::connection('pgsql')
            ->table('empleados')
            ->whereIn('id', $this->getSubordinadosIds())
            ->whereNotNull('sucursal_id')
            ->pluck('sucursal_id')
            ->unique()
            ->map(fn($id) => (int) $id)
            ->values()
            ->all();
    }
}
