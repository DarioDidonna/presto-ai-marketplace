<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchArticlesTest extends TestCase
{
    use RefreshDatabase;

    private function titles(string $url): array
    {
        return $this->get($url)->assertOk()->viewData('articles')->pluck('title')->all();
    }

    public function test_search_shows_only_accepted_articles(): void
    {
        Article::factory()->accepted()->create(['title' => 'Moto d\'epoca perfetta', 'description' => 'Descrizione di prova']);
        Article::factory()->rejected()->create(['title' => 'Moto d\'epoca perfetta', 'description' => 'Descrizione da non mostrare']);
        Article::factory()->create(['title' => 'Moto in revisione', 'description' => 'Descrizione in attesa']);

        $response = $this->get('/annunci?query=Moto');

        $response->assertOk();
        $response->assertViewHas('articles', fn ($articles) => $articles->total() === 1);
        $response->assertSee('Moto d&#039;epoca perfetta', false);
        $response->assertDontSee('Descrizione da non mostrare', false);
        $response->assertDontSee('Moto in revisione', false);
    }

    public function test_old_search_url_redirects_keeping_the_parameters(): void
    {
        $this->get('/search/article?query=bici&sort=price_asc')
            ->assertRedirect(route('article.index', ['query' => 'bici', 'sort' => 'price_asc']));
    }

    public function test_filters_by_category_and_price_range(): void
    {
        $sport = Category::factory()->create();
        Article::factory()->accepted()->for($sport)->create(['title' => 'Bici economica', 'price' => 50]);
        Article::factory()->accepted()->for($sport)->create(['title' => 'Bici media', 'price' => 300]);
        Article::factory()->accepted()->for($sport)->create(['title' => 'Bici costosa', 'price' => 2000]);
        Article::factory()->accepted()->create(['title' => 'Divano', 'price' => 300]);

        $this->assertEqualsCanonicalizing(['Bici economica', 'Bici media', 'Bici costosa'], $this->titles("/annunci?category={$sport->id}"));
        $this->assertEqualsCanonicalizing(['Bici media', 'Divano'], $this->titles('/annunci?min_price=100&max_price=500'));
        $this->assertSame(['Bici media'], $this->titles("/annunci?category={$sport->id}&min_price=100&max_price=500"));
        // Prezzo con la virgola decimale e minimo/massimo invertiti
        $this->assertEqualsCanonicalizing(['Bici media', 'Divano'], $this->titles('/annunci?min_price=500&max_price=99,90'));
    }

    public function test_text_search_combines_with_filters(): void
    {
        $sport = Category::factory()->create();
        Article::factory()->accepted()->for($sport)->create(['title' => 'Bici da corsa', 'price' => 800]);
        Article::factory()->accepted()->for($sport)->create(['title' => 'Bici da bambino', 'price' => 60]);
        Article::factory()->accepted()->create(['title' => 'Bici elettrica', 'price' => 900]);

        $this->assertSame(['Bici da corsa'], $this->titles("/annunci?query=bici&category={$sport->id}&min_price=100"));
    }

    public function test_sorting_options(): void
    {
        Article::factory()->accepted()->create(['title' => 'Media', 'price' => 50, 'created_at' => now()->subDays(1)]);
        Article::factory()->accepted()->create(['title' => 'Cara', 'price' => 90, 'created_at' => now()->subDays(3)]);
        Article::factory()->accepted()->create(['title' => 'Economica', 'price' => 10, 'created_at' => now()->subDays(2)]);

        $this->assertSame(['Media', 'Economica', 'Cara'], $this->titles('/annunci'));
        $this->assertSame(['Economica', 'Media', 'Cara'], $this->titles('/annunci?sort=price_asc'));
        $this->assertSame(['Cara', 'Media', 'Economica'], $this->titles('/annunci?sort=price_desc'));
    }

    public function test_relevance_is_the_default_order_when_searching(): void
    {
        Article::factory()->accepted()->create(['title' => 'Lampada']);

        $this->get('/annunci?query=lampada')->assertViewHas('filters', fn ($f) => $f->sort === 'relevance');
        // Senza testo la pertinenza non ha senso: si torna ai più recenti
        $this->get('/annunci?sort=relevance')->assertViewHas('filters', fn ($f) => $f->sort === 'newest');
    }

    public function test_invalid_parameters_are_ignored_without_errors(): void
    {
        Article::factory()->count(2)->accepted()->create();

        foreach ([
            '/annunci?query[]=x',
            '/annunci?query=%20%20',
            '/annunci?category=999',
            '/annunci?category=abc',
            '/annunci?min_price=-5&max_price=ciao',
            '/annunci?sort=DROP%20TABLE',
            '/annunci?min_price[]=1',
        ] as $url) {
            $this->get($url)->assertOk()->assertViewHas('articles', fn ($a) => $a->total() === 2);
        }
    }

    public function test_filter_form_keeps_selected_values_and_offers_reset(): void
    {
        $category = Category::factory()->create();

        $this->get("/annunci?query=bici&category={$category->id}&min_price=10&sort=price_desc")
            ->assertSee('value="bici"', false)
            ->assertSee('<option value="'.$category->id.'" selected>', false)
            ->assertSee('value="10"', false)
            ->assertSee('<option value="price_desc" selected>', false)
            ->assertSee(__('ui.reset_filters'));

        $this->get('/annunci')->assertDontSee(__('ui.reset_filters'));
    }

    public function test_partial_words_in_the_title_are_found(): void
    {
        Article::factory()->accepted()->create(['title' => 'Bicicletta da corsa', 'description' => 'Telaio in alluminio']);
        Article::factory()->accepted()->create(['title' => 'Smartphone 128 GB', 'description' => 'Come nuovo']);

        $this->assertSame(['Bicicletta da corsa'], $this->titles('/annunci?query=bici'));
        $this->assertSame(['Smartphone 128 GB'], $this->titles('/annunci?query=smart'));
    }

    public function test_symbol_only_search_shows_everything(): void
    {
        Article::factory()->count(3)->accepted()->create();

        foreach (['%', '_', '%%%'] as $symbols) {
            $this->get('/annunci?query='.urlencode($symbols))
                ->assertOk()
                ->assertViewHas('articles', fn ($a) => $a->total() === 3)
                ->assertViewHas('filters', fn ($f) => $f->query === null);
        }
    }

    public function test_common_words_are_ignored_and_all_keywords_must_match(): void
    {
        Article::factory()->accepted()->create(['title' => 'Bicicletta da corsa', 'description' => 'Telaio leggero']);
        Article::factory()->accepted()->create(['title' => 'Lampada da terra', 'description' => 'Stile industriale']);
        Article::factory()->accepted()->create(['title' => 'Bici da bambino', 'description' => 'Con rotelle']);

        // "da" compare in tutti i titoli: prima restituiva l'intero catalogo
        $this->assertSame(['Bicicletta da corsa'], $this->titles('/annunci?query=bici%20da%20corsa'));
        $this->assertSame([], $this->titles('/annunci?query=da'));
    }

    public function test_falls_back_to_partial_matches_when_no_ad_has_every_word(): void
    {
        Article::factory()->accepted()->create(['title' => 'Cuccia per cani', 'description' => 'Taglia media']);
        Article::factory()->accepted()->create(['title' => 'Divano letto', 'description' => 'Tre posti']);

        $this->assertSame(['Cuccia per cani'], $this->titles('/annunci?query=oggetti%20per%20cani'));
    }
}
