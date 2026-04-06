<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $connection = 'compras';
    protected $table = 'productos';
    protected $fillable = [
        'categoria_id', 'codigo', 'nombre', 'unidad', 'unidad_base', 'factor_conversion',
        'precio', 'costo', 'origen', 'activo', 'aud_usuario', 'codigo_origen', 'modificado_localmente',
    ];
    protected $casts = [
        'precio'               => 'decimal:4',
        'costo'                => 'decimal:4',
        'factor_conversion'    => 'decimal:4',
        'activo'               => 'boolean',
        'modificado_localmente'=> 'boolean',
    ];
    public function categoria()
    {
        return $this->belongsTo(Categoria::class);
    }
    public function detalles()
    {
        return $this->hasMany(PedidoDetalle::class);
    }
}
