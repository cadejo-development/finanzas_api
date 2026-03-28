<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Receta extends Model
{
    protected $connection = 'compras';
    protected $table = 'recetas';

    protected $fillable = [
        'nombre',
        'descripcion',
        'instrucciones',
        'tipo',
        'categoria_id',
        'tipo_receta',
        'precio',
        'platos_semana',
        'rendimiento',
        'rendimiento_unidad',
        'activa',
        'foto_plato',
        'foto_plateria',
        'aud_usuario',
    ];

    protected $casts = [
        'platos_semana' => 'integer',
        'activa'        => 'boolean',
    ];

    /**
     * Ingredientes de la receta (con producto cargado).
     */
    public function ingredientes()
    {
        return $this->hasMany(RecetaIngrediente::class);
    }

    /**
     * Ingredientes con los datos del producto incluidos.
     */
    public function ingredientesConProducto()
    {
        return $this->hasMany(RecetaIngrediente::class)->with('producto');
    }

    /**
     * Modificadores de la receta (opciones de sustitución agrupadas por grupo).
     */
    public function modificadores()
    {
        return $this->hasMany(RecetaModificador::class)->orderBy('grupo_id_origen')->orderBy('opcion_nombre');
    }

    /**
     * Producto asociado (misma clave: productos.codigo = recetas.codigo_origen).
     * Útil para obtener el costo pre-calculado en SQL Server para sub-recetas.
     */
    public function productoAsociado()
    {
        return $this->hasOne(Producto::class, 'codigo', 'codigo_origen');
    }

    /**
     * Categoría a la que pertenece esta receta.
     */
    public function categoria()
    {
        return $this->belongsTo(RecetaCategoria::class, 'categoria_id');
    }

    /**
     * Configuración de platos_semana por sucursal.
     */
    public function sucursalConfig(): HasMany
    {
        return $this->hasMany(RecetaSucursal::class);
    }

    /**
     * Devuelve los platos/semana para una sucursal concreta.
     * Si no hay registro específico, usa el valor global de la receta.
     */
    public function platosParaSucursal(?int $sucursalId): int
    {
        if ($sucursalId === null) {
            return $this->platos_semana ?? 0;
        }

        $cfg = $this->relationLoaded('sucursalConfig')
            ? $this->sucursalConfig->firstWhere('sucursal_id', $sucursalId)
            : $this->sucursalConfig()->where('sucursal_id', $sucursalId)->first();

        return $cfg?->platos_semana ?? $this->platos_semana ?? 0;
    }
}
