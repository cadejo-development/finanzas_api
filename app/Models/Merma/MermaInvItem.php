<?php

namespace App\Models\Merma;

use Illuminate\Database\Eloquent\Model;

class MermaInvItem extends Model
{
    protected $connection = 'compras';
    protected $table      = 'merma_inv_items';
    public    $timestamps = false;

    protected $fillable = [
        'inventario_id', 'cerveza_id',
        'inicial_oz', 'final_cerrados_p', 'final_cerrados_g',
    ];

    protected $casts = [
        'inicial_oz' => 'float',
    ];

    public function cerveza()
    {
        return $this->belongsTo(MermaCerveza::class, 'cerveza_id');
    }

    public function barrilesConectados()
    {
        return $this->hasMany(MermaBarrilConectado::class, 'item_id');
    }
}
