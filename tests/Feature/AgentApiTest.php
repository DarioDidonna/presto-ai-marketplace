<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'token-di-prova-molto-lungo';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_agent.token' => self::TOKEN]);
    }

    private function agent(): static
    {
        return $this->withToken(self::TOKEN);
    }

    public function test_requires_a_valid_token(): void
    {
        $this->getJson('/api/agent/categories')->assertUnauthorized();
        $this->withToken('sbagliato')->getJson('/api/agent/categories')->assertUnauthorized();
        $this->agent()->getJson('/api/agent/categories')->assertOk();
    }

    public function test_api_is_disabled_when_no_token_is_configured(): void
    {
        config(['services.ai_agent.token' => '']);

        $this->withToken('')->getJson('/api/agent/categories')->assertStatus(503);
        $this->getJson('/api/agent/articles')->assertStatus(503);
    }

    public function test_logged_in_users_cannot_use_the_api_without_token(): void
    {
        $this->actingAs(User::factory()->revisor()->create())->getJson('/api/agent/articles')->assertUnauthorized();
    }

    public function test_categories_with_published_counts_and_translated_names(): void
    {
        $category = Category::create(['name' => 'Casa e Giardinaggio']);
        Article::factory()->count(2)->accepted()->for($category)->create();
        Article::factory()->for($category)->create();

        $this->agent()->getJson('/api/agent/categories?lang=en')
            ->assertOk()
            ->assertJsonPath('categories.0.name', __('ui.casa_e_giardinaggio', [], 'en'))
            ->assertJsonPath('categories.0.published_count', 2);
    }

    public function test_search_returns_only_published_articles_with_filters(): void
    {
        $sport = Category::factory()->create();
        Article::factory()->accepted()->for($sport)->create(['title' => 'Bici da corsa', 'price' => 450]);
        Article::factory()->accepted()->for($sport)->create(['title' => 'Bici elettrica', 'price' => 1500]);
        Article::factory()->for($sport)->create(['title' => 'Bici in revisione', 'price' => 100]);
        Article::factory()->rejected()->for($sport)->create(['title' => 'Bici rifiutata', 'price' => 100]);

        $response = $this->agent()->getJson("/api/agent/articles?query=bici&category={$sport->id}&max_price=500&sort=price_asc")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('articles.0.title', 'Bici da corsa')
            ->assertJsonPath('filters.max_price', 500);

        $this->assertStringNotContainsString('revisione', $response->getContent());
        $this->assertStringNotContainsString('rifiutata', $response->getContent());
    }

    public function test_search_result_shape_and_limit(): void
    {
        Article::factory()->count(15)->accepted()->create();

        $this->agent()->getJson('/api/agent/articles')
            ->assertJsonCount(5, 'articles')
            ->assertJsonPath('total', 15)
            ->assertJsonStructure(['articles' => [['id', 'title', 'price', 'category', 'description', 'url', 'image_url', 'published_at']]]);

        $this->agent()->getJson('/api/agent/articles?limit=100')->assertJsonCount(10, 'articles');
        $this->agent()->getJson('/api/agent/articles?limit=abc')->assertJsonCount(5, 'articles');
    }

    public function test_article_detail_without_personal_data(): void
    {
        $seller = User::factory()->create(['name' => 'Giulia Romano', 'email' => 'giulia@privata.it']);
        $article = Article::factory()->accepted()->for($seller)->create(['description' => "Riga uno\nRiga due"]);

        $response = $this->agent()->getJson("/api/agent/articles/{$article->id}")
            ->assertOk()
            ->assertJsonPath('article.id', $article->id)
            ->assertJsonPath('article.seller', 'Giulia Romano')
            ->assertJsonPath('article.description', 'Riga uno Riga due')
            ->assertJsonPath('article.url', route('articles.show', $article));

        $this->assertStringNotContainsString('giulia@privata.it', $response->getContent());
        $this->assertStringNotContainsString('password', $response->getContent());
    }

    public function test_reserved_flag_is_exposed(): void
    {
        Article::factory()->accepted()->create(['reserved_at' => now()]);

        $this->agent()->getJson('/api/agent/articles')->assertJsonPath('articles.0.reserved', true);
    }

    public function test_unpublished_or_missing_articles_are_not_found(): void
    {
        foreach ([Article::factory()->create(), Article::factory()->rejected()->create()] as $article) {
            $this->agent()->getJson("/api/agent/articles/{$article->id}")->assertNotFound();
        }

        $this->agent()->getJson('/api/agent/articles/99999')->assertNotFound()->assertJsonStructure(['message']);
    }
}
