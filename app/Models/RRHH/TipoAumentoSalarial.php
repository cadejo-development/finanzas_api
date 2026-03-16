<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class TipoAumentoSalarial extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'tipos_aumento_salarial';

    protected $fillable = ['codigo', 'nombre', 'activo', 'aud_usuario'];

    protected $casts = ['activo' => 'boolean'];

    public function cambiosSalariales()
    {
        return $this->hasMany(CambioSalarial::class, 'tipo_aumento_id');
    }
}
