<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Desactiva categorías de recetas que no corresponden al catálogo de platos:
 *   - Sub-Recetas (tienen su propia pantalla, no deben aparecer en el catálogo principal)
 *   - Materia Prima (son ingredientes, no recetas)
 *   - Promocionales (mercancía, no preparaciones culinarias)
 *   - Aguas Duras (si existe)
 *
 * Las recetas de esas categorías siguen existiendo en BD; solo desaparece el
 * filtro del dropdown. Se pueden reactivar manualmente si es necesario.
 */
return new class extends Migration
{
    public function getConnection(): string { return 'compras'; }

    /** Patrones (case-insensitive) de nombres de categorías a desactivar. */
    private array $desactivar = [
        'Sub-Receta',
        'Platos Sub-Recetas',
        'Materia Prima Alimentos',
        'Materia Prima Proteinas',
        'Materia Prima Proteínas',
        'Promocionales Accesorios',
        'Promocionales Ropa y Calzado',
        'Promocionales Vasos y Porta Bebidas',
        'Aguas Duras',
    ];

    public function up(): void
    {
        $now = now();

        foreach ($this->desactivar as $nombre) {
            DB::connection('compras')
                ->table('receta_categorias')
                ->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])
                ->update(['activa' => false, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        $now = now();

        foreach ($this->desactivar as $nombre) {
            DB::connection('compras')
                ->table('receta_categorias')
                ->whereRaw('lower(nombre) = ?', [mb_strtolower($nombre)])
                ->update(['activa' => true, 'updated_at' => $now]);
        }
    }
};
