<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use App\Models\BrewLevaduraLote;
use App\Models\BrewLevaduraPitch;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class BrewLevaduraLotesController extends Controller
{
    public function index(Request $request)
    {
        $q = BrewLevaduraLote::with('pitches');

        if ($request->has('activo')) {
            $q->where('activo', filter_var($request->activo, FILTER_VALIDATE_BOOLEAN));
        }

        return $q->orderByDesc('fecha_inicio')->get()->map(function ($lote) {
            $arr = $lote->toArray();
            $arr['vol_usado_ml']   = $lote->vol_usado_ml;
            $arr['vol_restante_ml'] = $lote->vol_restante_ml;
            $arr['costo_por_ml']   = $lote->costo_por_ml;
            return $arr;
        });
    }

    public function show($id)
    {
        $lote = BrewLevaduraLote::with(['pitches.lote:id,codigo_lote,fecha_coccion'])->findOrFail($id);
        $arr = $lote->toArray();
        $arr['vol_usado_ml']    = $lote->vol_usado_ml;
        $arr['vol_restante_ml'] = $lote->vol_restante_ml;
        $arr['costo_por_ml']    = $lote->costo_por_ml;
        return $arr;
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'cepa'              => 'required|string|max:100',
            'codigo_lab'        => 'nullable|string|max:50',
            'proveedor'         => 'nullable|string|max:100',
            'precio_total_usd'  => 'nullable|numeric|min:0',
            'vol_total_ml'      => 'nullable|numeric|min:0',
            'fecha_inicio'      => 'nullable|date',
            'fecha_vencimiento' => 'nullable|date',
            'activo'            => 'boolean',
            'notas'             => 'nullable|string',
        ]);

        $lote = BrewLevaduraLote::create($data);
        return response()->json($lote, 201);
    }

    public function update(Request $request, $id)
    {
        $lote = BrewLevaduraLote::findOrFail($id);
        $data = $request->validate([
            'cepa'              => 'sometimes|required|string|max:100',
            'codigo_lab'        => 'nullable|string|max:50',
            'proveedor'         => 'nullable|string|max:100',
            'precio_total_usd'  => 'nullable|numeric|min:0',
            'vol_total_ml'      => 'nullable|numeric|min:0',
            'fecha_inicio'      => 'nullable|date',
            'fecha_vencimiento' => 'nullable|date',
            'activo'            => 'boolean',
            'notas'             => 'nullable|string',
        ]);

        $lote->update($data);
        return response()->json($lote);
    }

    public function destroy($id)
    {
        $lote = BrewLevaduraLote::findOrFail($id);
        if ($lote->pitches()->exists()) {
            return response()->json(['message' => 'No se puede eliminar: tiene pitches registrados'], 422);
        }
        $lote->delete();
        return response()->json(['ok' => true]);
    }

    public function storePitch(Request $request, $id)
    {
        $levLote = BrewLevaduraLote::findOrFail($id);

        $data = $request->validate([
            'brew_lote_id'      => 'nullable|exists:compras.brew_lotes,id',
            'numero_generacion' => 'nullable|integer|min:1|max:20',
            'fecha'             => 'required|date',
            'vol_pitch_ml'      => 'nullable|numeric|min:0',
            'viabilidad_pct'    => 'nullable|numeric|min:0|max:100',
            'og_pitch'          => 'nullable|numeric',
            'temp_pitch'        => 'nullable|numeric',
            'notas'             => 'nullable|string',
        ]);

        // Calcular costo proporcional
        $costoEstimado = null;
        if ($levLote->precio_total_usd && $levLote->vol_total_ml && ($data['vol_pitch_ml'] ?? 0)) {
            $costoEstimado = round(
                ((float) $data['vol_pitch_ml'] / (float) $levLote->vol_total_ml) * (float) $levLote->precio_total_usd,
                4
            );
        }

        $pitch = $levLote->pitches()->create(array_merge($data, [
            'costo_estimado_usd' => $costoEstimado,
        ]));

        return response()->json($pitch, 201);
    }

    public function destroyPitch($id, $pitchId)
    {
        $pitch = BrewLevaduraPitch::where('brew_levadura_lote_id', $id)->findOrFail($pitchId);
        $pitch->delete();
        return response()->json(['ok' => true]);
    }
}
