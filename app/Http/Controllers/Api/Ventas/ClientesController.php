<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaCliente;
use App\Models\Ventas\VentaCatalogoPrecio;
use Illuminate\Http\Request;

class ClientesController extends Controller
{
    public function index(Request $request)
    {
        $q = VentaCliente::query()->with('catalogoPrecio')->where('activo', true);

        if ($request->filled('search')) {
            $search = '%' . $request->search . '%';
            $q->where(function ($w) use ($search) {
                $w->whereRaw('LOWER(nombres) LIKE LOWER(?)', [$search])
                  ->orWhereRaw('LOWER(nom_comercial) LIKE LOWER(?)', [$search])
                  ->orWhereRaw('LOWER(nit) LIKE LOWER(?)', [$search]);
            });
        }

        if ($request->filled('tipo')) {
            if ($request->tipo === 'credito') {
                $q->where('limite_credito', '>', 0);
            } elseif ($request->tipo === 'contado') {
                $q->where('limite_credito', '<=', 0);
            }
        }

        $clientes = $q->orderBy('nombres')->paginate(50);

        $clientes->getCollection()->transform(fn($c) => $this->format($c));

        return response()->json($clientes);
    }

    public function show($id)
    {
        $c = VentaCliente::with('catalogoPrecio')->findOrFail($id);
        return response()->json($this->format($c));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombres'           => 'required|string|max:200',
            'nom_comercial'     => 'nullable|string|max:200',
            'nit'               => 'nullable|string|max:30',
            'registro_iva'      => 'nullable|string|max:30',
            'email'             => 'nullable|email|max:150',
            'telefono'          => 'nullable|string|max:30',
            'direccion'         => 'nullable|string|max:300',
            'exento'            => 'boolean',
            'plazo_credito'     => 'integer|min:0',
            'limite_credito'    => 'numeric|min:0',
            'catalogo_precio_id' => 'nullable|integer|exists:compras.ventas_catalogos_precio,id',
        ]);

        $c = VentaCliente::create($data);
        return response()->json($this->format($c->load('catalogoPrecio')), 201);
    }

    public function update(Request $request, $id)
    {
        $c = VentaCliente::findOrFail($id);

        $data = $request->validate([
            'nombres'           => 'sometimes|string|max:200',
            'nom_comercial'     => 'nullable|string|max:200',
            'nit'               => 'nullable|string|max:30',
            'registro_iva'      => 'nullable|string|max:30',
            'email'             => 'nullable|email|max:150',
            'telefono'          => 'nullable|string|max:30',
            'direccion'         => 'nullable|string|max:300',
            'exento'            => 'boolean',
            'plazo_credito'     => 'integer|min:0',
            'limite_credito'    => 'numeric|min:0',
            'catalogo_precio_id' => 'nullable|integer|exists:compras.ventas_catalogos_precio,id',
        ]);

        $c->update($data);
        return response()->json($this->format($c->load('catalogoPrecio')));
    }

    private function format(VentaCliente $c): array
    {
        return [
            'id'                  => $c->id,
            'brilo_id'            => $c->brilo_id,
            'nombres'             => $c->nombres,
            'nom_comercial'       => $c->nom_comercial,
            'nit'                 => $c->nit,
            'registro_iva'        => $c->registro_iva,
            'email'               => $c->email,
            'telefono'            => $c->telefono,
            'direccion'           => $c->direccion,
            'exento'              => $c->exento,
            'tipo_cliente'        => $c->tipo_cliente,
            'plazo_credito'       => $c->plazo_credito,
            'limite_credito'      => $c->limite_credito,
            'activo'              => $c->activo,
            'catalogo_precio_id'  => $c->catalogo_precio_id,
            'catalogo_nombre'     => $c->catalogoPrecio?->nombre,
        ];
    }
}
