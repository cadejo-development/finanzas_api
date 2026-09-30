<?php

namespace App\Services\Compras;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class PurchasePlanningService
{
    private string $apiUrl;
    private string $apiKey;

    public function __construct()
    {
        $this->apiUrl = config('services.purchase_planning.url', '');
        $this->apiKey = config('services.purchase_planning.key', '');
    }

    // Nuestro sucursal_id → Brilo sucId (olComun.dbo.Sucursales)
    private const SUCURSAL_MAP = [
        1  => 3,   // Zona Rosa
        2  => 2,   // Santa Rosa
        3  => 6,   // La Libertad
        4  => 7,   // Aeropuerto #1
        5  => 8,   // Aeropuerto #2
        6  => 9,   // San Miguel
        7  => 10,  // Paseo Venecia
        8  => 11,  // Santa Elena
        9  => 12,  // Huizúcar
        10 => 13,  // Opico
        11 => 19,  // Casa Guirola
        16 => 16,  // Malcriadas AE2
    ];

    public function getBriloSucId(int $sucursalId): ?int
    {
        return self::SUCURSAL_MAP[$sucursalId] ?? null;
    }

    /**
     * Proyecta unidades para un conjunto de productos.
     *
     * @param  int    $sucursalId  Nuestro sucursal_id
     * @param  string $desde       Y-m-d inicio período de proyección
     * @param  string $hasta       Y-m-d fin período de proyección
     * @param  array  $productos   [{codigo, proId, nombre, categoria_key}, ...]
     * @return array  [codigo => {qty_proyectada, origen, tipoModelo}]
     */
    public function proyectar(int $sucursalId, string $desde, string $hasta, array $productos): array
    {
        $sucId = $this->getBriloSucId($sucursalId);
        if (!$sucId || empty($productos)) return [];

        $itemsByProId = [];
        foreach ($productos as $prod) {
            if (empty($prod['proId'])) continue;
            $itemsByProId[(int) $prod['proId']] = $prod;
        }

        if (empty($itemsByProId)) return [];

        $items = array_values(array_map(
            fn($p) => ['sucId' => $sucId, 'proId' => (int) $p['proId']],
            $itemsByProId
        ));

        $data = $this->call($desde, $hasta, $items);

        $resultado = [];
        foreach ($data['proyecciones'] ?? [] as $proj) {
            $proId = (int) ($proj['proId'] ?? 0);
            if (!isset($itemsByProId[$proId])) continue;

            $codigo   = $itemsByProId[$proId]['codigo'];
            $qtyTotal = array_sum($proj['unidades'] ?? []);

            $resultado[$codigo] = [
                'qty_proyectada' => max(0.0, (float) $qtyTotal),
                'origen'         => $proj['origen'] ?? 'desconocido',
                'tipoModelo'     => $proj['tipoModelo'] ?? 'desconocido',
            ];
        }

        return $resultado;
    }

    /**
     * Llamada HTTP directa a la API.
     *
     * @param  string $fechaInicio  Y-m-d
     * @param  string $fechaFin     Y-m-d
     * @param  array  $items        [{sucId, proId}, ...]
     * @return array  Respuesta completa: {fechas[], proyecciones[]}
     */
    public function call(string $fechaInicio, string $fechaFin, array $items): array
    {
        if (empty($items)) return ['fechas' => [], 'proyecciones' => []];

        // Ordenar para que la clave de cache sea determinística sin importar el orden de entrada
        usort($items, fn($a, $b) => $a['proId'] <=> $b['proId']);
        $cacheKey = 'pp_api_' . md5($fechaInicio . $fechaFin . serialize($items));

        return Cache::remember($cacheKey, now()->addHours(3), function () use ($fechaInicio, $fechaFin, $items) {
            $url = $this->apiUrl . '?code=' . $this->apiKey;

            $response = Http::withHeaders(['Content-Type' => 'application/json'])
                ->timeout(90)
                ->post($url, [
                    'fechaInicio' => $fechaInicio,
                    'fechaFin'    => $fechaFin,
                    'items'       => $items,
                ]);

            if (!$response->successful()) {
                Log::error('PurchasePlanningService error', [
                    'status' => $response->status(),
                    'body'   => substr($response->body(), 0, 500),
                ]);
                throw new \RuntimeException('CDJ_PurchasePlanning API error: HTTP ' . $response->status());
            }

            return $response->json() ?? ['fechas' => [], 'proyecciones' => []];
        });
    }
}
