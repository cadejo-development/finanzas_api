<?php

namespace App\Http\Controllers\Api\Compras;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Catalogos de ingredientes desde Brilo (SQL Server) y materias primas locales.
 * Lanza la excepcion original para que el cliente pueda depurar.
 */
class BrewCatalogosController extends Controller
{
    // GET /api/compras/brew/catalogos/maltas
    public function maltas()
    {
        return response()->json($this->queryBrilo(
            "SELECT LTRIM(RTRIM(proCodigo)) AS codigo,
                    LTRIM(RTRIM(proNombre))  AS nombre
             FROM   olComun.dbo.Productos WITH (NOLOCK)
             WHERE  proActivo = 1
               AND  (
                     LOWER(proNombre) LIKE '%malta%'
                  OR LOWER(proNombre) LIKE '%malt%'
                  OR LOWER(proNombre) LIKE '%grain%'
                  OR LOWER(proNombre) LIKE '%grano%'
                  OR LOWER(proNombre) LIKE '%trigo%'
                  OR LOWER(proNombre) LIKE '%cebada%'
                  OR LOWER(proNombre) LIKE '%avena%'
                  OR LOWER(proNombre) LIKE '%wheat%'
                  OR LOWER(proNombre) LIKE '%barley%'
                  OR LOWER(proNombre) LIKE '%centeno%'
                  OR LOWER(proNombre) LIKE '%pilsen%'
                  OR LOWER(proNombre) LIKE '%caramel%'
                  OR LOWER(proNombre) LIKE '%crystal%'
                  OR LOWER(proNombre) LIKE '%black%'
                  OR LOWER(proNombre) LIKE '%roast%'
                  OR LOWER(proNombre) LIKE '%chocolate%'
                  OR LOWER(proNombre) LIKE '%munich%'
                  OR LOWER(proNombre) LIKE '%vienna%'
                  OR LOWER(proNombre) LIKE '%pale%'
               )
             ORDER BY proNombre"
        ));
    }

    // GET /api/compras/brew/catalogos/lupulos
    public function lupulos()
    {
        return response()->json($this->queryBrilo(
            "SELECT LTRIM(RTRIM(proCodigo)) AS codigo,
                    LTRIM(RTRIM(proNombre))  AS nombre
             FROM   olComun.dbo.Productos WITH (NOLOCK)
             WHERE  proActivo = 1
               AND  (
                     LOWER(proNombre) LIKE '%lupulo%'
                  OR LOWER(proNombre) LIKE '%l%pulo%'
                  OR LOWER(proNombre) LIKE '%hop%'
                  OR LOWER(proNombre) LIKE '%cascade%'
                  OR LOWER(proNombre) LIKE '%centennial%'
                  OR LOWER(proNombre) LIKE '%chinook%'
                  OR LOWER(proNombre) LIKE '%citra%'
                  OR LOWER(proNombre) LIKE '%simcoe%'
                  OR LOWER(proNombre) LIKE '%galaxy%'
                  OR LOWER(proNombre) LIKE '%mosaic%'
                  OR LOWER(proNombre) LIKE '%saaz%'
                  OR LOWER(proNombre) LIKE '%hallertau%'
                  OR LOWER(proNombre) LIKE '%fuggle%'
                  OR LOWER(proNombre) LIKE '%amarillo%'
                  OR LOWER(proNombre) LIKE '%equinox%'
                  OR LOWER(proNombre) LIKE '%el dorado%'
                  OR LOWER(proNombre) LIKE '%magnum%'
               )
             ORDER BY proNombre"
        ));
    }

    // GET /api/compras/brew/catalogos/cervezas
    public function cervezas()
    {
        return response()->json($this->queryBrilo(
            "SELECT LTRIM(RTRIM(proCodigo)) AS codigo,
                    LTRIM(RTRIM(proNombre))  AS nombre
             FROM   olComun.dbo.Productos WITH (NOLOCK)
             WHERE  proActivo = 1
               AND  (
                     LOWER(proNombre) LIKE '%cadejo%'
                  OR LOWER(proNombre) LIKE '%cerveza%'
                  OR LOWER(proNombre) LIKE '%lager%'
                  OR LOWER(proNombre) LIKE '%ale%'
                  OR LOWER(proNombre) LIKE '%stout%'
                  OR LOWER(proNombre) LIKE '%porter%'
                  OR LOWER(proNombre) LIKE '%ipa%'
                  OR LOWER(proNombre) LIKE '%pilsner%'
                  OR LOWER(proNombre) LIKE '%wheat%'
                  OR LOWER(proNombre) LIKE '%tripel%'
                  OR LOWER(proNombre) LIKE '%dubbel%'
                  OR LOWER(proNombre) LIKE '%saison%'
                  OR LOWER(proNombre) LIKE '%sour%'
               )
             ORDER BY proNombre"
        ));
    }

    // GET /api/compras/brew/catalogos/materias-primas
    public function materiasPrimas()
    {
        try {
            $data = DB::connection('compras')
                ->table('productos as p')
                ->leftJoin('categorias as c', 'p.categoria_id', '=', 'c.id')
                ->where('p.activo', true)
                ->select('p.codigo', 'p.nombre', 'p.unidad', 'c.nombre as categoria')
                ->orderBy('p.nombre')
                ->get();
            return response()->json($data);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    // Helper: query Brilo, retorna el error exacto si falla
    private function queryBrilo(string $sql): array
    {
        $result = DB::connection('origen')->select($sql);
        return collect($result)->map(fn($r) => [
            'codigo' => $r->codigo ?? '',
            'nombre' => $r->nombre ?? '',
        ])->values()->all();
    }
}
