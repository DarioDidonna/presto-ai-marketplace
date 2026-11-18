<?php

namespace Tests\Feature;

use App\Models\Article;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OpenGraphTest extends TestCase
{
    use RefreshDatabase;

    private function meta(string $html, string $property): ?string
    {
        $attr = str_starts_with($property, 'og:') || str_starts_with($property, 'product:') ? 'property' : 'name';
        preg_match('/<meta '.$attr.'="'.preg_quote($property, '/').'" content="([^"]*)"/', $html, $m);

        return isset($m[1]) ? html_entity_decode($m[1], ENT_QUOTES) : null;
    }

    public function test_every_page_has_share_preview_with_default_image(): void
    {
        $html = $this->get('/')->getContent();

        $this->assertSame('website', $this->meta($html, 'og:type'));
        $this->assertSame('Presto.it - '.__('ui.meta_tagline'), $this->meta($html, 'og:title'));
        $this->assertSame(__('ui.meta_description'), $this->meta($html, 'og:description'));
        $this->assertSame(asset('media/og-default.jpg'), $this->meta($html, 'og:image'));
        $this->assertSame('1200', $this->meta($html, 'og:image:width'));
        $this->assertSame('summary_large_image', $this->meta($html, 'twitter:card'));
        $this->assertSame('it_IT', $this->meta($html, 'og:locale'));
        $this->assertStringContainsString('<link rel="canonical" href="'.url('/').'">', $html);
        $this->assertFileExists(public_path('media/og-default.jpg'));
    }

    public function test_article_preview_uses_its_photo_title_and_price(): void
    {
        Storage::fake('public');
        $article = Article::factory()->accepted()->create(['title' => 'Bici "vintage" da corsa', 'price' => 349.9, 'description' => "Telaio in acciaio\nGomme nuove"]);
        $image = $article->images()->create(['path' => "articles/{$article->id}/foto.jpg"]);
        Storage::disk('public')->put("articles/{$article->id}/crop_800x800_foto.jpg", 'x');

        $html = $this->get(route('articles.show', $article))->getContent();

        $this->assertSame('product', $this->meta($html, 'og:type'));
        $this->assertSame('Bici "vintage" da corsa · Presto.it', $this->meta($html, 'og:title'));
        $this->assertSame('Telaio in acciaio Gomme nuove', $this->meta($html, 'og:description')); // a capo compattati
        $this->assertSame(url($image->getDisplayUrl()), $this->meta($html, 'og:image'));
        $this->assertStringStartsWith('http', $this->meta($html, 'og:image'));
        $this->assertSame('349.90', $this->meta($html, 'product:price:amount'));
        $this->assertSame('EUR', $this->meta($html, 'product:price:currency'));
        $this->assertNull($this->meta($html, 'robots'));
    }

    public function test_article_without_photos_uses_default_image(): void
    {
        $article = Article::factory()->accepted()->create();

        $html = $this->get(route('articles.show', $article))->getContent();
        $this->assertSame(asset('media/og-default.jpg'), $this->meta($html, 'og:image'));
    }

    public function test_unpublished_article_is_not_indexed(): void
    {
        $article = Article::factory()->create();

        $html = $this->actingAs($article->user)->get(route('articles.show', $article))->getContent();
        $this->assertSame('noindex', $this->meta($html, 'robots'));
    }

    public function test_preview_language_follows_the_site_language(): void
    {
        $html = $this->withSession(['locale' => 'en'])->get('/')->getContent();

        $this->assertSame('en_GB', $this->meta($html, 'og:locale'));
        $this->assertSame(__('ui.meta_description', [], 'en'), $this->meta($html, 'og:description'));
    }
}
