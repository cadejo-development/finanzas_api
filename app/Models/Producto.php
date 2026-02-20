<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Producto extends Model
{
    protected $table = 'productos';
    protected $fillable = [
        'categoria_id', 'codigo', 'nombre', 'unidad', 'precio', 'activo', 'aud_usuario'
    ];
    protected $casts = [
        'precio' => 'decimal:2',
        'activo' => 'boolean',
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
