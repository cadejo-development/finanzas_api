<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaCatalogoPrecio extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_catalogos_precio';

    protected $fillable = ['nombre', 'descripcion', 'activo', 'dias_alerta_revision'];

    protected $casts = ['activo' => 'boolean', 'dias_alerta_revision' => 'integer'];

    public function lineas()
    {
        return $this->hasMany(VentaCatalogoPrecioLinea::class, 'catalogo_id');
    }

    public function clientes()
    {
        return $this->hasMany(VentaCliente::class, 'catalogo_precio_id');
    }

    public function necesitaRevision(): bool
    {
        $diasSinActualizar = $this->updated_at->diffInDays(now());
        return $diasSinActualizar >= $this->dias_alerta_revision;
    }
}
