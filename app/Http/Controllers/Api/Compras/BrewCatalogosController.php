<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Catalogos de ingredientes de cerveza.
 * Lee de la tabla brew_ingredientes en el RDS local (compras_db).
 * Para popular/sincronizar desde Brilo ejecutar (con VPN activa):
 *   php artisan db:seed --class=BrewIngredientesSeeder
 */
class BrewCatalogosController extends Controller
{
    // GET /api/compras/brew/catalogos/maltas
    public function maltas()
    {
        return response()->json($this->porTipo('malta'));
    }

    // GET /api/compras/brew/catalogos/lupulos
    public function lupulos()
    {
        return response()->json($this->porTipo('lupulo'));
    }

    // GET /api/compras/brew/catalogos/cervezas
    public function cervezas()
    {
        try {
            $data = DB::connection('compras')
                ->table('brew_ingredientes')
                ->where('tipo', 'cerveza')
                ->orderBy('nombre')
                ->select('codigo', 'nombre', 'estilo')
                ->get()
                ->map(fn($r) => [
                    'codigo' => $r->codigo ?? '',
                    'nombre' => $r->nombre ?? '',
                    'estilo' => $r->estilo ?? '',
                ])
                ->filter(fn($r) => $r['nombre'] !== '')
                ->values()
                ->all();

            return response()->json($data);
        } catch (\Exception $e) {
            \Log::error('brew_catalogo[cerveza]: ' . $e->getMessage());
            return response()->json([]);
        }
    }

    // GET /api/compras/brew/catalogos/levaduras
    public function levaduras()
    {
        return response()->json($this->porTipo('levadura'));
    }

    // GET /api/compras/brew/catalogos/minerales
    public function minerales()
    {
        return response()->json($this->porTipo('mineral'));
    }

    // GET /api/compras/brew/catalogos/materias-primas
    public function materiasPrimas()
    {
        $data = DB::connection('compras')
            ->table('productos as p')
            ->leftJoin('categorias as c', 'p.categoria_id', '=', 'c.id')
            ->where('p.activo', true)
            ->select('p.codigo', 'p.nombre', 'p.unidad', 'c.nombre as categoria')
            ->orderBy('p.nombre')
            ->get();

        return response()->json($data);
    }

    // ── Helper ────────────────────────────────────────────────────────────────
    private function porTipo(string $tipo): array
    {
        try {
            return DB::connection('compras')
                ->table('brew_ingredientes')
                ->where('tipo', $tipo)
                ->orderBy('nombre')
                ->select('codigo', 'nombre')
                ->get()
                ->map(fn($r) => ['codigo' => $r->codigo ?? '', 'nombre' => $r->nombre ?? ''])
                ->filter(fn($r) => $r['nombre'] !== '')
                ->values()
                ->all();
        } catch (\Exception $e) {
            \Log::error("brew_catalogo[$tipo]: " . $e->getMessage());
            return [];
        }
    }
}
