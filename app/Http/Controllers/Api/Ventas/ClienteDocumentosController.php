<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaCliente;
use App\Models\Ventas\VentaClienteDocumento;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ClienteDocumentosController extends Controller
{
    public function index(int $clienteId)
    {
        $cliente = VentaCliente::findOrFail($clienteId);
        $docs    = VentaClienteDocumento::where('cliente_id', $cliente->id)->orderByDesc('id')->get();

        return response()->json(['data' => $docs]);
    }

    public function store(Request $request, int $clienteId)
    {
        $cliente = VentaCliente::findOrFail($clienteId);

        $request->validate([
            'tipo'       => 'required|in:tarjeta_iva,carta_exencion,otro',
            'archivo'    => 'required|file|mimes:pdf,jpg,jpeg,png,webp|max:20480',
            'subido_por' => 'nullable|string|max:100',
        ]);

        $file          = $request->file('archivo');
        $nombreArchivo = $file->getClientOriginalName();
        $ruta          = $file->store("ventas/clientes/{$cliente->id}/documentos", 's3');

        $doc = VentaClienteDocumento::create([
            'cliente_id'     => $cliente->id,
            'tipo'           => $request->tipo,
            'nombre_archivo' => $nombreArchivo,
            'ruta_archivo'   => $ruta,
            'subido_por'     => $request->subido_por ?? null,
        ]);

        return response()->json(['success' => true, 'data' => $doc], 201);
    }

    public function download(int $clienteId, int $docId)
    {
        $doc = VentaClienteDocumento::where('cliente_id', $clienteId)->findOrFail($docId);
        $url = Storage::disk('s3')->temporaryUrl($doc->ruta_archivo, now()->addMinutes(15));

        return response()->json(['success' => true, 'url' => $url]);
    }

    public function destroy(int $clienteId, int $docId)
    {
        $doc = VentaClienteDocumento::where('cliente_id', $clienteId)->findOrFail($docId);

        Storage::disk('s3')->delete($doc->ruta_archivo);
        $doc->delete();

        return response()->json(['success' => true]);
    }
}
