<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_return_a_successful_response(): void
    {
        $category = Category::factory()->create();
        $article = Article::factory()->accepted()->for($category)->create();

        foreach (['/', '/annunci', "/categorie/{$category->id}", "/annunci/{$article->id}", '/login', '/register'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_protected_pages_redirect_guests_to_login(): void
    {
        $this->get(route('create.article'))->assertRedirect(route('login'));
    }

    public function test_missing_records_return_404(): void
    {
        $this->get('/annunci/999')->assertNotFound();
        $this->get('/categorie/999')->assertNotFound();
    }
}
