<?php

namespace Tests\Feature;

use App\Jobs\RemoveFaces;
use App\Models\Article;
use App\Models\Image;
use App\Models\User;
use App\Services\AiAgentClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RemoveFacesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config(['services.ai_agent.url' => 'http://agent.test', 'services.ai_agent.token' => 'segreto']);
    }

    // Foto 400x300 bianca (o con un quadratino rosso in alto a sinistra), salvata come JPEG
    private function photo(?\GdImage $gd = null, string $bytes = ''): Image
    {
        if ($bytes === '') {
            $gd ??= $this->canvas(400, 300);
            ob_start();
            imagejpeg($gd, null, 95);
            $bytes = ob_get_clean();
        }
        Storage::disk('public')->put('articles/1/foto.jpg', $bytes);

        return Article::factory()->create()->images()->create(['path' => 'articles/1/foto.jpg']);
    }

    private function canvas(int $width, int $height, bool $marker = false): \GdImage
    {
        $gd = imagecreatetruecolor($width, $height);
        imagefill($gd, 0, 0, imagecolorallocate($gd, 255, 255, 255));
        if ($marker) {
            imagefilledrectangle($gd, 0, 0, 9, 9, imagecolorallocate($gd, 255, 0, 0));
        }

        return $gd;
    }

    private function process(Image $image, int $attempts = 1): RemoveFaces
    {
        $job = (new RemoveFaces($image->id))->withFakeQueueInteractions();
        $job->job->attempts = $attempts;
        $job->handle(app(AiAgentClient::class));

        return $job;
    }

    private function stored(): \GdImage
    {
        return imagecreatefromstring(Storage::disk('public')->get('articles/1/foto.jpg'));
    }

    private function rgb(\GdImage $gd, int $x, int $y): array
    {
        $color = imagecolorat($gd, $x, $y);

        return [($color >> 16) & 0xFF, ($color >> 8) & 0xFF, $color & 0xFF];
    }

    private function fakeFaces(array $faces, int $width = 400, int $height = 300): void
    {
        Http::fake(['agent.test/faces' => Http::response(['width' => $width, 'height' => $height, 'faces' => $faces])]);
    }

    public function test_faces_found_by_the_local_service_are_blacked_out_and_counted(): void
    {
        $this->fakeFaces([['x' => 100, 'y' => 50, 'width' => 60, 'height' => 80, 'score' => 0.9]]);
        $image = $this->photo();

        $this->process($image);

        $gd = $this->stored();
        $this->assertSame([0, 0, 0], $this->rgb($gd, 130, 90)); // dentro il riquadro
        $this->assertGreaterThan(240, $this->rgb($gd, 300, 200)[0]); // fuori
        $this->assertSame(1, $image->fresh()->faces_count);

        Http::assertSent(fn (Request $request) => $request->url() === 'http://agent.test/faces'
            && $request->hasHeader('Authorization', 'Bearer segreto')
            && getimagesizefromstring(base64_decode($request['image']))[0] === 400);
    }

    public function test_no_faces_leaves_the_photo_untouched_and_records_zero(): void
    {
        $this->fakeFaces([]);
        $image = $this->photo();
        $before = Storage::disk('public')->get('articles/1/foto.jpg');

        $this->process($image);

        $this->assertSame($before, Storage::disk('public')->get('articles/1/foto.jpg'));
        $this->assertSame(0, $image->fresh()->faces_count);
        $this->assertTrue($image->fresh()->facesChecked());
    }

    public function test_boxes_are_rescaled_if_the_service_saw_a_different_size(): void
    {
        $this->fakeFaces([['x' => 10, 'y' => 10, 'width' => 20, 'height' => 20]], 200, 150);
        $image = $this->photo();

        $this->process($image);

        $this->assertSame([0, 0, 0], $this->rgb($this->stored(), 50, 50)); // 25 * 2
    }

    public function test_service_down_is_retried_and_then_the_chain_goes_on_with_faces_unchecked(): void
    {
        Http::fake(['agent.test/faces' => fn () => throw new ConnectionException('rifiutata')]);
        $image = $this->photo();

        $this->process($image, attempts: 1)->assertReleased(30);
        $this->process($image, attempts: 3)->assertReleased(600);

        // Ultimo tentativo: niente eccezione (la catena prosegue con miniature e analisi), volti non controllati
        $this->process($image, attempts: 4)->assertNotReleased();
        $this->assertNull($image->fresh()->faces_count);
    }

    public function test_a_format_the_detector_cannot_read_is_left_unchecked_without_retrying(): void
    {
        Http::fake();
        $gd = $this->canvas(40, 20);
        ob_start();
        imagegif($gd);
        $image = $this->photo(bytes: ob_get_clean()); // un GIF caricato quando erano ancora ammessi
        $before = Storage::disk('public')->get('articles/1/foto.jpg');

        $this->process($image)->assertNotReleased();

        Http::assertNothingSent();
        $this->assertNull($image->fresh()->faces_count); // il revisore lo vede "non controllato"
        $this->assertSame($before, Storage::disk('public')->get('articles/1/foto.jpg'));
    }

    public function test_without_the_ai_service_configured_nothing_is_called(): void
    {
        config(['services.ai_agent.token' => '']);
        Http::fake();
        $image = $this->photo();

        $this->process($image)->assertNotReleased();

        Http::assertNothingSent();
        $this->assertNull($image->fresh()->faces_count);
    }

    /**
     * Ogni orientazione EXIF: si salva la foto "come la scatta il telefono" e il job deve raddrizzarla
     * (quadratino rosso di nuovo in alto a sinistra, 40x20).
     *
     * @return array<string, array{int}>
     */
    public static function orientations(): array
    {
        return collect(range(1, 8))->mapWithKeys(fn ($o) => ["orientazione $o" => [$o]])->all();
    }

    #[DataProvider('orientations')]
    public function test_photos_are_straightened_according_to_exif_orientation(int $orientation): void
    {
        $this->fakeFaces([]);
        $upright = $this->canvas(40, 20, marker: true);

        // Foto memorizzata = trasformazione inversa di quella che l'EXIF chiede di applicare
        $raw = match ($orientation) {
            1 => $upright,
            2 => $this->flipped($upright),
            3 => imagerotate($upright, 180, 0),
            4 => $this->flipped(imagerotate($upright, 180, 0)),
            5 => imagerotate($this->flipped($upright), 90, 0),   // trasposta
            6 => imagerotate($upright, 90, 0),                   // ruotata a sinistra: l'EXIF dice "gira a destra"
            7 => imagerotate($this->flipped($upright), 270, 0),  // trasversa
            8 => imagerotate($upright, 270, 0),
        };
        ob_start();
        imagejpeg($raw, null, 95);
        $image = $this->photo(bytes: $this->withExifOrientation(ob_get_clean(), $orientation));

        $this->process($image);

        $gd = $this->stored();
        $this->assertSame([40, 20], [imagesx($gd), imagesy($gd)]);
        [$r, $g, $b] = $this->rgb($gd, 3, 3);
        $this->assertTrue($r > 200 && $g < 80 && $b < 80, "quadratino rosso non in alto a sinistra (orientazione $orientation)");
        $this->assertGreaterThan(200, $this->rgb($gd, 36, 16)[1]);
    }

    private function flipped(\GdImage $gd): \GdImage
    {
        $copy = imagecreatetruecolor(imagesx($gd), imagesy($gd));
        imagecopy($copy, $gd, 0, 0, 0, 0, imagesx($gd), imagesy($gd));
        imageflip($copy, IMG_FLIP_HORIZONTAL);

        return $copy;
    }

    // Blocco EXIF minimo con il solo tag Orientation, subito dopo l'inizio del JPEG
    private function withExifOrientation(string $jpeg, int $orientation): string
    {
        $tiff = "MM\x00\x2a\x00\x00\x00\x08".pack('n', 1).pack('nnNnn', 0x0112, 3, 1, $orientation, 0)."\x00\x00\x00\x00";
        $payload = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    public function test_reviewer_sees_how_many_faces_were_hidden(): void
    {
        $article = Article::factory()->create();
        $article->images()->create(['path' => 'articles/1/a.jpg'])->forceFill(['faces_count' => 2])->save();
        $article->images()->create(['path' => 'articles/1/b.jpg'])->forceFill(['faces_count' => 0])->save();
        $article->images()->create(['path' => 'articles/1/c.jpg']);

        $this->actingAs(User::factory()->revisor()->create())->get(route('revisor.index'))
            ->assertSee(trans_choice('ui.faces_hidden', 2, ['count' => 2]))
            ->assertSee(trans_choice('ui.faces_hidden', 0, ['count' => 0]))
            ->assertSee(__('ui.faces_unchecked'));
    }
}
