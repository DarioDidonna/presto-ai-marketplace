<?php

namespace Tests\Feature;

use App\Jobs\RemoveFaces;
use App\Livewire\CreateArticleForm;
use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class CreateArticleFormTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_must_exist(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(CreateArticleForm::class)
            ->set('form.title', 'Bicicletta')
            ->set('form.price', 100)
            ->set('form.description', 'Bicicletta da corsa in ottimo stato')
            ->set('form.category', 999)
            ->call('store')
            ->assertHasErrors(['form.category' => 'exists']);

        $this->assertSame(0, Article::count());
    }

    public function test_cannot_upload_more_than_max_images(): void
    {
        Storage::fake('public');
        $files = array_map(fn ($n) => UploadedFile::fake()->image("f$n.jpg"), range(1, CreateArticleForm::MAX_IMAGES + 1));

        Livewire::actingAs(User::factory()->create())
            ->test(CreateArticleForm::class)
            ->set('temporary_images', $files)
            ->assertHasErrors(['temporary_images' => 'max'])
            ->assertCount('images', 0);
    }

    public function test_non_image_upload_is_rejected(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(CreateArticleForm::class)
            ->set('temporary_images', [UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])
            ->assertHasErrors(['temporary_images.0' => 'image']);
    }

    public function test_gif_is_rejected_like_for_the_avatar(): void
    {
        // Il rilevatore dei volti non legge i GIF: accettarli voleva dire foto mai controllate
        Livewire::actingAs(User::factory()->create())
            ->test(CreateArticleForm::class)
            ->set('temporary_images', [UploadedFile::fake()->image('animazione.gif')])
            ->assertHasErrors(['temporary_images.0' => 'mimes'])
            ->assertCount('images', 0);
    }

    public function test_valid_article_is_stored_and_messages_are_translated(): void
    {
        Queue::fake();
        Storage::fake('public');
        $category = Category::factory()->create();

        app()->setLocale('en');
        Livewire::actingAs(User::factory()->create())
            ->test(CreateArticleForm::class)
            ->set('form.title', 'abc')
            ->assertSee('The title must be at least 5 characters.')
            ->set('form.title', 'Bicicletta')
            ->set('form.price', 100)
            ->set('form.description', 'Bicicletta da corsa in ottimo stato')
            ->set('form.category', $category->id)
            ->set('temporary_images', [UploadedFile::fake()->image('a.jpg')])
            ->call('store')
            ->assertHasNoErrors();

        $article = Article::first();
        $this->assertNotNull($article);
        $this->assertNull($article->is_accepted);
        $this->assertCount(1, $article->images);
        Queue::assertPushed(RemoveFaces::class);
    }

    public function test_english_locale_is_selectable_and_ukrainian_is_not(): void
    {
        $this->post('/lingua/uk')->assertNotFound();
        $this->post('/lingua/en')->assertRedirect();
        $this->get('/')->assertOk()->assertSee('lang="en"', false);
    }

    public function test_category_name_is_translated(): void
    {
        $category = Category::create(['name' => 'Casa e Giardinaggio']);
        app()->setLocale('en');
        $this->assertSame(__('ui.casa_e_giardinaggio'), $category->translated_name);
        $this->assertSame('Inventata', Category::create(['name' => 'Inventata'])->translated_name);
    }

    public function test_upload_error_disappears_after_a_valid_upload(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(CreateArticleForm::class)
            ->set('temporary_images', [UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])
            ->assertHasErrors('temporary_images.0')
            ->set('temporary_images', [UploadedFile::fake()->image('ok.jpg')])
            ->assertHasNoErrors()
            ->assertCount('images', 1);
    }

    public function test_empty_form_shows_all_field_errors_at_once(): void
    {
        Livewire::actingAs(User::factory()->create())
            ->test(CreateArticleForm::class)
            ->call('store')
            ->assertHasErrors([
                'form.title' => 'required',
                'form.price' => 'required',
                'form.description' => 'required',
                'form.category' => 'required',
            ])
            ->assertSee(__('ui.validation_title_required'))
            ->assertSee(__('ui.validation_category_required'));

        $this->assertSame(0, Article::count());
    }

    // JPEG 40x20 con un blocco EXIF minimo: l'orientazione scelta e, come dato da non pubblicare, un testo riconoscibile
    private function jpegWithExif(int $orientation): string
    {
        $gd = imagecreatetruecolor(40, 20);
        ob_start();
        imagejpeg($gd);
        $jpeg = ob_get_clean();

        $tiff = "MM\x00\x2a\x00\x00\x00\x08".pack('n', 1).pack('nnNnn', 0x0112, 3, 1, $orientation, 0)."\x00\x00\x00\x00POSIZIONE-GPS";
        $payload = "Exif\x00\x00".$tiff;

        return substr($jpeg, 0, 2)."\xFF\xE1".pack('n', strlen($payload) + 2).$payload.substr($jpeg, 2);
    }

    private function storeArticleWith(UploadedFile $photo): string
    {
        Queue::fake();
        Storage::fake('public');

        Livewire::actingAs(User::factory()->create())
            ->test(CreateArticleForm::class)
            ->set('form.title', 'Bicicletta')
            ->set('form.price', 100)
            ->set('form.description', 'Bicicletta da corsa in ottimo stato')
            ->set('form.category', Category::factory()->create()->id)
            ->set('temporary_images', [$photo])
            ->call('store')
            ->assertHasNoErrors();

        return Article::sole()->images()->sole()->path;
    }

    public function test_photo_metadata_is_removed_before_the_file_becomes_public(): void
    {
        $path = $this->storeArticleWith(UploadedFile::fake()->createWithContent('foto.jpg', $this->jpegWithExif(1)));

        $stored = Storage::disk('public')->get($path);
        $this->assertStringNotContainsString('POSIZIONE-GPS', $stored);
        $this->assertStringNotContainsString("Exif\x00\x00", $stored);
    }

    public function test_removing_metadata_keeps_the_photo_upright(): void
    {
        $path = $this->storeArticleWith(UploadedFile::fake()->createWithContent('foto.jpg', $this->jpegWithExif(6)));

        // Orientazione 6: la foto 40x20 va girata di 90°, quindi diventa verticale
        $this->assertSame([20, 40], array_slice(getimagesize(Storage::disk('public')->path($path)), 0, 2));
    }

    public function test_command_cleans_photos_uploaded_before(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('articles/1/vecchia.jpg', $this->jpegWithExif(1));
        Article::factory()->create()->images()->create(['path' => 'articles/1/vecchia.jpg']);

        $this->artisan('app:strip-image-metadata')->assertSuccessful();

        $this->assertStringNotContainsString('POSIZIONE-GPS', Storage::disk('public')->get('articles/1/vecchia.jpg'));
    }
}
