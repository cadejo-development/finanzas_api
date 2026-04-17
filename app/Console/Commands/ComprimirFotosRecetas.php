<?php

namespace App\Console\Commands;

use Aws\S3\S3Client;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Recorre todas las recetas que tienen foto_plato o foto_plateria,
 * descarga cada imagen de S3, la redimensiona a máx 1920px con GD,
 * y la vuelve a subir al mismo key (sobreescribe).
 * No cambia las URLs ni toca la base de datos.
 *
 * Uso:
 *   php artisan recetas:comprimir-fotos
 *   php artisan recetas:comprimir-fotos --dry-run        # solo muestra, no sube
 *   php artisan recetas:comprimir-fotos --max-dim=1280   # reducción más agresiva
 *   php artisan recetas:comprimir-fotos --calidad=80
 */
class ComprimirFotosRecetas extends Command
{
    protected $signature = 'recetas:comprimir-fotos
                            {--dry-run    : Solo muestra qué haría, sin modificar nada}
                            {--max-dim=1920 : Dimensión máxima en píxeles (ancho o alto)}
                            {--calidad=85   : Calidad JPEG (1-100)}
                            {--solo=        : Procesar solo un receta_id específico}';

    protected $description = 'Comprime y redimensiona las fotos de recetas ya almacenadas en S3';

    private S3Client $s3;

    public function handle(): int
    {
        // Imágenes grandes necesitan bastante RAM en GD
        ini_set('memory_limit', '512M');

        if (! extension_loaded('gd')) {
            $this->error('La extensión GD de PHP no está disponible. Instala php-gd e intenta de nuevo.');
            return self::FAILURE;
        }

        $dryRun  = (bool) $this->option('dry-run');
        $maxDim  = (int)  $this->option('max-dim');
        $calidad = (int)  $this->option('calidad');
        $soloId  = $this->option('solo') ? (int) $this->option('solo') : null;

        // Las credenciales y bucket pueden venir de config O directamente de variables de entorno
        // En producción (App Runner) AWS_BUCKET está en las env vars del servidor, no en .env local.
        // Por eso extraemos bucket/region/key de las URLs almacenadas en la DB cuando config esté vacío.
        $this->region = config('filesystems.disks.s3.region') ?: env('AWS_DEFAULT_REGION', 'us-east-1');

        $this->s3 = new S3Client([
            'region'      => $this->region,
            'version'     => 'latest',
            'credentials' => [
                'key'    => config('filesystems.disks.s3.key')    ?: env('AWS_ACCESS_KEY_ID'),
                'secret' => config('filesystems.disks.s3.secret') ?: env('AWS_SECRET_ACCESS_KEY'),
            ],
        ]);

        $this->info("Configuración: max_dim={$maxDim}px, calidad={$calidad}%" . ($dryRun ? ' [DRY-RUN]' : ''));
        $this->newLine();

        // Recoger todas las fotos únicas (foto_plato + foto_plateria)
        $query = DB::connection('compras')
            ->table('recetas')
            ->where(function ($q) {
                $q->whereNotNull('foto_plato')->orWhereNotNull('foto_plateria');
            });

        if ($soloId) {
            $query = DB::connection('compras')
                ->table('recetas')
                ->where('id', $soloId);
        }

        $recetas = $query->get(['id', 'nombre', 'foto_plato', 'foto_plateria']);

        // Aplanar en una lista de [receta_id, campo, url, bucket, key]
        // Extraemos bucket/key de la URL directamente para no depender de config vacío
        $tareas = [];
        foreach ($recetas as $r) {
            foreach (['foto_plato', 'foto_plateria'] as $campo) {
                $url = $r->$campo ?? null;
                if (! $url) continue;

                // Parsear la URL para extraer bucket y key
                // Formato: https://{bucket}.s3.{region}.amazonaws.com/{key}
                if (! preg_match('#^https://([^.]+)\.s3\.[^.]+\.amazonaws\.com/(.+)$#', $url, $m)) continue;

                $tareas[] = [
                    'id'     => $r->id,
                    'nombre' => $r->nombre,
                    'campo'  => $campo,
                    'url'    => $url,
                    'bucket' => $m[1],
                    'key'    => $m[2],
                ];
            }
        }

        if (empty($tareas)) {
            $this->warn('No se encontraron fotos para procesar.');
            return self::SUCCESS;
        }

        $this->info("Total de fotos a procesar: " . count($tareas));
        $this->newLine();

        $ok      = 0;
        $omitidas = 0;
        $errores = 0;

        $bar = $this->output->createProgressBar(count($tareas));
        $bar->setFormat(' %current%/%max% [%bar%] %percent:3s%% — %message%');
        $bar->start();

        foreach ($tareas as $tarea) {
            $key    = $tarea['key'];
            $bucket = $tarea['bucket'];
            $bar->setMessage("[{$tarea['id']}] {$tarea['nombre']} ({$tarea['campo']})");
            $bar->advance();

            // En dry-run solo listamos sin descargar nada
            if ($dryRun) {
                $this->newLine();
                $this->line("  [DRY] {$bucket}/{$key}");
                $ok++;
                continue;
            }

            try {
                // Descargar desde S3
                $result  = $this->s3->getObject(['Bucket' => $bucket, 'Key' => $key]);
                $binario = (string) $result['Body'];
                $mime    = $result['ContentType'] ?? 'image/jpeg';
                $tamOriginal = strlen($binario);

                // Crear imagen GD y liberar el binario crudo cuanto antes
                $src = @imagecreatefromstring($binario);
                unset($binario); // liberar memoria inmediatamente
                if (! $src) {
                    $this->newLine();
                    $this->warn("  No se pudo leer como imagen: {$key}");
                    $errores++;
                    continue;
                }

                $w = imagesx($src);
                $h = imagesy($src);

                // Si ya es suficientemente pequeña, omitir
                if ($w <= $maxDim && $h <= $maxDim) {
                    imagedestroy($src);
                    $omitidas++;
                    continue;
                }

                // Calcular nuevas dimensiones
                $ratio = min($maxDim / $w, $maxDim / $h);
                $nw    = (int) round($w * $ratio);
                $nh    = (int) round($h * $ratio);

                // Redimensionar
                $dst = imagecreatetruecolor($nw, $nh);

                // Preservar transparencia si es PNG
                if ($mime === 'image/png') {
                    imagealphablending($dst, false);
                    imagesavealpha($dst, true);
                    $transparente = imagecolorallocatealpha($dst, 0, 0, 0, 127);
                    imagefill($dst, 0, 0, $transparente);
                }

                imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
                imagedestroy($src); // liberar imagen original

                // Capturar output en buffer
                ob_start();
                if ($mime === 'image/png') {
                    imagepng($dst, null, 6);
                } else {
                    imagejpeg($dst, null, $calidad);
                }
                $nuevoBinario = ob_get_clean();
                imagedestroy($dst);

                $tamNuevo = strlen($nuevoBinario);

                // Subir de vuelta al mismo key
                $this->s3->putObject([
                    'Bucket'      => $bucket,
                    'Key'         => $key,
                    'Body'        => $nuevoBinario,
                    'ContentType' => $mime === 'image/png' ? 'image/png' : 'image/jpeg',
                ]);

                $this->newLine();
                $reduccion = round((1 - $tamNuevo / $tamOriginal) * 100);
                $this->line(sprintf(
                    '  ✓ %s: %dx%d → %dx%d | %s → %s (-%d%%)',
                    basename($key),
                    $w, $h, $nw, $nh,
                    $this->formatBytes($tamOriginal),
                    $this->formatBytes($tamNuevo),
                    $reduccion,
                ));

                $ok++;

            } catch (\Throwable $e) {
                $this->newLine();
                $this->error("  Error en {$key}: " . $e->getMessage());
                $errores++;
            }
        }

        $bar->finish();
        $this->newLine(2);

        $this->table(
            ['', 'Cantidad'],
            [
                ['Procesadas correctamente', $ok],
                ['Omitidas (ya optimizadas)', $omitidas],
                ['Errores', $errores],
            ]
        );

        if ($dryRun) {
            $this->warn('Modo DRY-RUN: no se modificó nada en S3.');
        }

        return $errores > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_048_576) return round($bytes / 1_048_576, 1) . ' MB';
        if ($bytes >= 1_024)     return round($bytes / 1_024, 1)     . ' KB';
        return $bytes . ' B';
    }
}
