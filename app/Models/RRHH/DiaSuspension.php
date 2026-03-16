<?php

namespace App\Models\RRHH;

use Illuminate\Database\Eloquent\Model;

class DiaSuspension extends Model
{
    protected $connection = 'rrhh';
    protected $table = 'dias_suspension';

    protected $fillable = ['amonestacion_id', 'fecha', 'aud_usuario'];

    protected $casts = ['fecha' => 'date'];

    public function amonestacion()
    {
        return $this->belongsTo(Amonestacion::class, 'amonestacion_id');
    }
}
