<?php

namespace App\Jobs;

use App\Models\Image;
use App\Services\AgentUnavailableException;
use App\Services\AiAgentClient;
use App\Support\PhotoMetadata;
use Closure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Primo anello della catena di elaborazione: raddrizza la foto e ne oscura i volti con il rilevatore locale del
 * servizio Python (YuNet). Salva in faces_count quanti volti ha oscurato, per il revisore.
 *
 * Se il servizio è spento ritenta più tardi; dopo l'ultimo tentativo la catena prosegue comunque (miniature),
 * con faces_count vuoto: la foto non è pubblica finché un revisore, avvisato, non l'approva.
 */
class RemoveFaces implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public function __construct(public int $imageId) {}

    /**
     * @return list<int>
     */
    public function backoff(): array
    {
        return [30, 120, 600];
    }

    public function handle(AiAgentClient $agent): void
    {
        $image = Image::find($this->imageId);
        $path = $image ? Storage::disk('public')->path($image->path) : null;
        if (! $image || ! is_file($path)) {
            logger()->warning("RemoveFaces: immagine {$this->imageId} non trovata");

            return;
        }

        // Il rilevatore legge solo JPEG, PNG e WebP: un altro formato (un GIF caricato in passato) resta "non controllato"
        // e il revisore lo vede, invece di ritentare quattro volte un errore che non può risolversi
        $save = $this->writerFor($path);
        if (! $save) {
            logger()->warning("RemoveFaces [{$this->imageId}]: formato non supportato dal rilevatore, volti non controllati");

            return;
        }

        $this->straighten($path);

        if (! $agent->isEnabled()) {
            logger()->warning("RemoveFaces [{$this->imageId}]: servizio AI non configurato, volti non controllati");

            return;
        }

        try {
            $result = $agent->faces($path);
        } catch (AgentUnavailableException $error) {
            if ($this->attempts() < $this->tries) {
                $this->release($this->backoff()[$this->attempts() - 1] ?? 600);

                return;
            }
            report($error);

            return;
        }

        $image->faces_count = $this->blackOut($path, $save, $result);
        $image->save();
    }

    /**
     * Applica la rotazione EXIF ai pixel e salva la foto dritta: il rilevatore e GD lavorano così sulla stessa
     * immagine (prima le foto del telefono "di lato" avevano i riquadri nel punto sbagliato). Le foto nuove arrivano
     * già dritte (HandlesArticleForm), questo resta per quelle caricate prima.
     */
    private function straighten(string $path): void
    {
        $orientation = function_exists('exif_read_data') && @getimagesize($path)[2] === IMAGETYPE_JPEG
            ? (int) (@exif_read_data($path)['Orientation'] ?? 1)
            : 1;

        if ($orientation > 1 && $orientation <= 8) {
            PhotoMetadata::strip($path);
        }
    }

    /**
     * Riempie di nero i riquadri ricevuti (già allargati dal servizio) e salva la foto.
     *
     * @param  Closure(\GdImage): bool  $save
     * @param  array{width: int, height: int, faces: list<array{x: int, y: int, width: int, height: int}>}  $result
     */
    private function blackOut(string $path, Closure $save, array $result): int
    {
        if ($result['faces'] === []) {
            return 0;
        }

        $gd = @imagecreatefromstring((string) file_get_contents($path));
        if (! $gd) {
            return 0;
        }

        // Stessa griglia di pixel attesa; se per qualunque motivo differisce, i riquadri vengono riscalati
        $scaleX = imagesx($gd) / max(1, $result['width']);
        $scaleY = imagesy($gd) / max(1, $result['height']);
        $black = imagecolorallocate($gd, 0, 0, 0);

        foreach ($result['faces'] as $face) {
            imagefilledrectangle(
                $gd,
                (int) floor($face['x'] * $scaleX),
                (int) floor($face['y'] * $scaleY),
                (int) ceil(($face['x'] + $face['width']) * $scaleX),
                (int) ceil(($face['y'] + $face['height']) * $scaleY),
                $black,
            );
        }

        $save($gd);

        return count($result['faces']);
    }

    /**
     * Come salvare la foto nel suo stesso formato, solo per quelli che il rilevatore sa leggere: un solo punto decide
     * entrambe le cose (mai un JPEG salvato con l'estensione di un altro formato).
     *
     * @return (Closure(\GdImage): bool)|null
     */
    private function writerFor(string $path): ?Closure
    {
        return match ((@getimagesize($path) ?: [])[2] ?? null) {
            IMAGETYPE_JPEG => fn (\GdImage $gd) => imagejpeg($gd, $path, 90),
            IMAGETYPE_PNG => fn (\GdImage $gd) => imagepng($gd, $path),
            IMAGETYPE_WEBP => fn (\GdImage $gd) => imagewebp($gd, $path, 90),
            default => null,
        };
    }
}
