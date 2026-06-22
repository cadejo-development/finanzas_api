<?php

namespace App\Http\Controllers\Api\Ventas;

use App\Http\Controllers\Controller;
use App\Models\Ventas\VentaCliente;
use App\Models\Ventas\VentaAprobacion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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

        // Calcular avg_dias_pago en un solo query para todos los clientes de la página
        $ids       = $clientes->pluck('id')->toArray();
        $pagStats  = DB::connection('compras')
            ->table('ventas_pagos as p')
            ->join('ventas_ordenes as o', 'o.id', '=', 'p.orden_id')
            ->whereIn('o.cliente_id', $ids)
            ->selectRaw('o.cliente_id, ROUND(AVG(DATEDIFF(p.fecha, DATE(o.created_at))), 0) as avg_dias, COUNT(DISTINCT o.id) as ord_pagadas')
            ->groupBy('o.cliente_id')
            ->get()
            ->keyBy('cliente_id');

        $clientes->getCollection()->transform(function ($c) use ($pagStats) {
            $st = $pagStats[$c->id] ?? null;
            return array_merge($this->format($c), [
                'avg_dias_pago'   => $st ? (int) $st->avg_dias   : null,
                'ordenes_pagadas' => $st ? (int) $st->ord_pagadas : 0,
            ]);
        });

        return response()->json($clientes);
    }

    public function show($id)
    {
        $c     = VentaCliente::with('catalogoPrecio')->findOrFail($id);
        $st    = DB::connection('compras')
            ->table('ventas_pagos as p')
            ->join('ventas_ordenes as o', 'o.id', '=', 'p.orden_id')
            ->where('o.cliente_id', $id)
            ->selectRaw('ROUND(AVG(DATEDIFF(p.fecha, DATE(o.created_at))), 0) as avg_dias, COUNT(DISTINCT o.id) as ord_pagadas')
            ->first();
        return response()->json(array_merge($this->format($c), [
            'avg_dias_pago'   => $st?->avg_dias   ? (int) $st->avg_dias   : null,
            'ordenes_pagadas' => $st?->ord_pagadas ? (int) $st->ord_pagadas : 0,
        ]));
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
        $c   = VentaCliente::findOrFail($id);
        $rol = $this->getRolFromRequest($request);

        $data = $request->validate([
            'nombres'            => 'sometimes|string|max:200',
            'nom_comercial'      => 'nullable|string|max:200',
            'nit'                => 'nullable|string|max:30',
            'registro_iva'       => 'nullable|string|max:30',
            'email'              => 'nullable|email|max:150',
            'telefono'           => 'nullable|string|max:30',
            'direccion'          => 'nullable|string|max:300',
            'exento'             => 'boolean',
            'plazo_credito'      => 'integer|min:0',
            'limite_credito'     => 'numeric|min:0',
            'catalogo_precio_id' => 'nullable|integer|exists:compras.ventas_catalogos_precio,id',
        ]);

        // Vendedor → envía a aprobación; jefe_ventas → guarda directamente
        if ($rol === 'vendedor') {
            $cambios = $this->describeCambios($c, $data);
            if (empty($cambios)) {
                return response()->json($this->format($c->load('catalogoPrecio')));
            }
            $campos = implode(', ', array_column($cambios, 'label'));
            VentaAprobacion::create([
                'tipo'          => 'cambio_cliente',
                'cliente_id'    => $c->id,
                'detalle'       => json_encode([
                    'descripcion' => "Cambio solicitado en: {$campos}",
                    'cambios'     => $cambios,
                    'datos'       => $data,
                ]),
                'estado'        => 'pendiente',
                'solicitado_por'=> $this->getNombreFromRequest($request),
            ]);
            return response()->json([
                'message'  => 'Cambios enviados a aprobación del jefe de ventas',
                'pendiente'=> true,
            ], 202);
        }

        $c->update($data);
        return response()->json($this->format($c->load('catalogoPrecio')));
    }

    private function getRolFromRequest(Request $request): string
    {
        $token = $request->bearerToken();
        if (!$token) return 'vendedor';
        [$username] = explode(':', base64_decode($token), 2);
        return match($username) { 'rodrigo' => 'jefe_ventas', default => 'vendedor' };
    }

    private function getNombreFromRequest(Request $request): string
    {
        $token = $request->bearerToken();
        if (!$token) return 'Vendedor';
        [$username] = explode(':', base64_decode($token), 2);
        return match($username) { 'rodrigo' => 'Rodrigo Jefe', 'vendedor' => 'Vendedor Demo', default => ucfirst($username) };
    }

    private function describeCambios(VentaCliente $actual, array $propuesto): array
    {
        $labels = [
            'nombres' => 'Nombre', 'nom_comercial' => 'Nombre comercial', 'nit' => 'NIT',
            'registro_iva' => 'Registro IVA', 'email' => 'Email', 'telefono' => 'Teléfono',
            'direccion' => 'Dirección', 'exento' => 'Exento IVA',
            'plazo_credito' => 'Plazo crédito', 'limite_credito' => 'Límite crédito',
            'catalogo_precio_id' => 'Catálogo de precios',
        ];
        $cambios = [];
        foreach ($propuesto as $campo => $nuevo) {
            if ((string) ($actual->$campo ?? '') !== (string) ($nuevo ?? '')) {
                $cambios[$campo] = ['label' => $labels[$campo] ?? $campo, 'de' => $actual->$campo, 'a' => $nuevo];
            }
        }
        return $cambios;
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
