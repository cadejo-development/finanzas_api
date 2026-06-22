<?php

namespace App\Models\Ventas;

use Illuminate\Database\Eloquent\Model;

class VentaClienteDocumento extends Model
{
    protected $connection = 'compras';
    protected $table = 'ventas_cliente_documentos';

    protected $fillable = [
        'cliente_id', 'tipo', 'nombre_archivo', 'ruta_archivo', 'subido_por',
    ];

    public function cliente()
    {
        return $this->belongsTo(VentaCliente::class, 'cliente_id');
    }
}
