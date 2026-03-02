<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TipoPersona extends Model
{
    protected $connection = 'pagos';
    protected $table = 'tipos_persona';

    protected $fillable = ['codigo', 'nombre', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function proveedores()
    {
        return $this->hasMany(Proveedor::class, 'tipo_persona_id');
    }
}
