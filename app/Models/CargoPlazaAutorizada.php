<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CargoPlazaAutorizada extends Model
{
    protected $connection = 'pgsql';
    protected $table      = 'cargo_plazas_autorizadas';

    protected $fillable = ['cargo_id', 'departamento_id', 'cantidad'];

    public function cargo()
    {
        return $this->belongsTo(Cargo::class);
    }

    public function departamento()
    {
        return $this->belongsTo(Departamento::class);
    }
}
