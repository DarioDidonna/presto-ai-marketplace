<?php

namespace Tests\Feature;

use App\Jobs\RemoveFaces;
use App\Jobs\ResizeImage;
use App\Models\Article;
use App\Models\Category;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ArticleFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_by_category_shows_only_accepted_articles_paginated(): void
    {
        $category = Category::factory()->create();
        Article::factory()->count(13)->accepted()->for($category)->create();
        Article::factory()->for($category)->create();
        Article::factory()->rejected()->for($category)->create();
        Article::factory()->accepted()->create(); // altra categoria

        $response = $this->get(route('categories.show', $category))->assertOk()
            ->assertSee('Mostrati da') // paginazione tradotta (prima: "Showing 1 to 12 of 13 results")
            ->assertDontSee('Showing');
        $this->assertCount(12, $response->viewData('articles'));
        $this->assertSame(13, $response->viewData('articles')->total());
    }

    public function test_revisor_carousel_shows_every_image(): void
    {
        Storage::fake('public');
        $article = Article::factory()->create();
        foreach (['a', 'b'] as $name) {
            $article->images()->create(['path' => "articles/1/{$name}.jpg"]);
            Storage::disk('public')->put("articles/1/{$name}.jpg", 'x');
            Storage::disk('public')->put("articles/1/crop_300x300_{$name}.jpg", 'x');
            Storage::disk('public')->put("articles/1/crop_800x800_{$name}.jpg", 'x');
        }

        $this->actingAs(User::factory()->revisor()->create())->get(route('revisor.index'))
            ->assertOk()
            ->assertSee('crop_300x300_a.jpg')
            ->assertSee('crop_300x300_b.jpg');
    }

    public function test_job_chain_creates_thumbnails_even_without_the_face_service(): void
    {
        Storage::fake('public');

        $article = Article::factory()->create();
        $path = UploadedFile::fake()->image('foto.jpg', 800, 600)->store("articles/{$article->id}", 'public');
        $image = $article->images()->create(['path' => $path]);

        RemoveFaces::withChain([
            new ResizeImage($image->path, 300, 300),
            new ResizeImage($image->path, Image::DISPLAY_SIZE, Image::DISPLAY_SIZE),
        ])->dispatch($image->id);

        Storage::disk('public')->assertExists(dirname($path).'/crop_300x300_'.basename($path));
        Storage::disk('public')->assertExists(dirname($path).'/crop_800x800_'.basename($path));
        $this->assertStringEndsWith('crop_800x800_'.basename($path), $image->getDisplayUrl());
    }

    public function test_prices_are_stored_exactly_up_to_the_maximum_allowed(): void
    {
        // Con il vecchio float a precisione singola 999999.99 diventava 1000000 (trovato con Larastan)
        foreach (['999999.99', '123456.78', '0.01'] as $price) {
            $article = Article::factory()->create(['price' => $price]);
            $this->assertSame($price, $article->fresh()->price);
        }
    }
}
