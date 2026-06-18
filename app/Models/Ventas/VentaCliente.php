<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaCliente extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_clientes';

    protected $fillable = [
        'brilo_id', 'nombres', 'nom_comercial', 'nit', 'registro_iva',
        'email', 'telefono', 'direccion', 'exento',
        'plazo_credito', 'limite_credito', 'brilo_status', 'brilo_vendedor_id', 'activo',
        'catalogo_precio_id',
    ];

    protected $casts = [
        'exento' => 'boolean',
        'activo' => 'boolean',
        'limite_credito' => 'float',
        'plazo_credito' => 'integer',
    ];

    public function catalogoPrecio()
    {
        return $this->belongsTo(VentaCatalogoPrecio::class, 'catalogo_precio_id');
    }

    public function ordenes()
    {
        return $this->hasMany(VentaOrden::class, 'cliente_id');
    }

    public function aprobaciones()
    {
        return $this->hasMany(VentaAprobacion::class, 'cliente_id');
    }

    public function getTipoClienteAttribute(): string
    {
        return $this->limite_credito > 0 ? 'credito' : 'contado';
    }
}
