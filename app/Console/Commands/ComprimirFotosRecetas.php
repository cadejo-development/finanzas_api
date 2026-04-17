<?php

namespace App\Console\Commands;

use App\Models\Receta;
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
    private string   $bucket;
    private string   $region;
    private string   $prefix;

    public function handle(): int
    {
        if (! extension_loaded('gd')) {
            $this->error('La extensión GD de PHP no está disponible. Instala php-gd e intenta de nuevo.');
            return self::FAILURE;
        }

        $dryRun  = (bool) $this->option('dry-run');
        $maxDim  = (int)  $this->option('max-dim');
        $calidad = (int)  $this->option('calidad');
        $soloId  = $this->option('solo') ? (int) $this->option('solo') : null;

        $this->bucket = config('filesystems.disks.s3.bucket');
        $this->region = config('filesystems.disks.s3.region');
        $this->prefix = "https://{$this->bucket}.s3.{$this->region}.amazonaws.com/";

        $this->s3 = new S3Client([
            'region'      => $this->region,
            'version'     => 'latest',
            'credentials' => [
                'key'    => config('filesystems.disks.s3.key'),
                'secret' => config('filesystems.disks.s3.secret'),
            ],
        ]);

        $this->info("Configuración: max_dim={$maxDim}px, calidad={$calidad}%" . ($dryRun ? ' [DRY-RUN]' : ''));
        $this->newLine();

        // Recoger todas las fotos únicas (foto_plato + foto_plateria)
        $query = DB::connection('compras')
            ->table('recetas')
            ->whereNotNull('foto_plato')
            ->orWhereNotNull('foto_plateria');

        if ($soloId) {
            $query = DB::connection('compras')
                ->table('recetas')
                ->where('id', $soloId);
        }

        $recetas = $query->get(['id', 'nombre', 'foto_plato', 'foto_plateria']);

        // Aplanar en una lista de [receta_id, campo, url]
        $tareas = [];
        foreach ($recetas as $r) {
            foreach (['foto_plato', 'foto_plateria'] as $campo) {
                $url = $r->$campo ?? null;
                if ($url && str_starts_with($url, $this->prefix)) {
                    $tareas[] = ['id' => $r->id, 'nombre' => $r->nombre, 'campo' => $campo, 'url' => $url];
                }
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
            $key  = substr($tarea['url'], strlen($this->prefix));
            $bar->setMessage("[{$tarea['id']}] {$tarea['nombre']} ({$tarea['campo']})");
            $bar->advance();

            try {
                // Descargar desde S3
                $result  = $this->s3->getObject(['Bucket' => $this->bucket, 'Key' => $key]);
                $binario = (string) $result['Body'];
                $mime    = $result['ContentType'] ?? 'image/jpeg';

                // Crear imagen GD
                $src = @imagecreatefromstring($binario);
                if (! $src) {
                    $this->newLine();
                    $this->warn("  No se pudo leer como imagen: {$key}");
                    $errores++;
                    continue;
                }

                $w = imagesx($src);
                $h = imagesy($src);

                // Verificar si ya es pequeña
                if ($w <= $maxDim && $h <= $maxDim) {
                    imagedestroy($src);
                    $omitidas++;
                    continue;
                }

                // Calcular nuevas dimensiones
                $ratio = min($maxDim / $w, $maxDim / $h);
                $nw    = (int) round($w * $ratio);
                $nh    = (int) round($h * $ratio);

                if ($dryRun) {
                    $this->newLine();
                    $this->line("  [DRY] {$key}: {$w}x{$h} → {$nw}x{$nh}");
                    imagedestroy($src);
                    $ok++;
                    continue;
                }

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
                imagedestroy($src);

                // Capturar output en buffer
                ob_start();
                if ($mime === 'image/png') {
                    imagepng($dst, null, 6); // compresión 0-9, 6 es buen balance
                } else {
                    imagejpeg($dst, null, $calidad);
                }
                $nuevoBinario = ob_get_clean();
                imagedestroy($dst);

                $tamOriginal = strlen($binario);
                $tamNuevo    = strlen($nuevoBinario);

                // Subir de vuelta al mismo key
                $this->s3->putObject([
                    'Bucket'      => $this->bucket,
                    'Key'         => $key,
                    'Body'        => $nuevoBinario,
                    'ContentType' => $mime === 'image/png' ? 'image/png' : 'image/jpeg',
                    'ACL'         => 'public-read',
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
