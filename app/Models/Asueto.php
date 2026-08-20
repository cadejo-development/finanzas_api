<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Asueto extends Model
{
    protected $connection = 'pgsql';
    protected $table = 'asuetos';

    protected $fillable = [
        'fecha',
        'nombre',
        'tipo',
        'geo_departamento_id',
        'activo',
        'creado_por',
    ];

    protected $casts = [
        'fecha'  => 'date',
        'activo' => 'boolean',
    ];

    public function geoDepartamento()
    {
        return $this->belongsTo(GeoDepartamento::class, 'geo_departamento_id');
    }
}
