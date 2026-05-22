<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class PlanillaAcreedor extends Model
{
    protected $connection = 'rrhh';
    protected $table      = 'planilla_acreedores';

    protected $fillable = ['nombre', 'tipo', 'activo'];

    protected $casts = [
        'activo' => 'boolean',
    ];

    public function ordenes()
    {
        return $this->hasMany(PlanillaOrdenDescuento::class, 'acreedor_id');
    }
}
