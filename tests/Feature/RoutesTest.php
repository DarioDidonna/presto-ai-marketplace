<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Tests\TestCase;

class RoutesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Pagine raggiungibili senza login, per URI. Ogni altra rotta del sito deve dichiarare in routes/web.php chi
     * può usarla: una rotta nuova senza protezione fa fallire questo test invece di finire online aperta a tutti.
     */
    private const PUBLIC_URIS = [
        '/',
        'lingua/{lang}',
        'annunci',
        'annunci/{article}',
        'categorie/{category}',
        'venditori/{user}',
        // Vecchi indirizzi: solo redirect verso le pagine nuove, che hanno le proprie protezioni
        'article/index',
        'search/article',
        'create/article',
        'show/article/{article}',
        'article/{article}/edit',
        'category/{category}',
        'venditore/{user}',
        'revisor/index',
    ];

    private const PROTECTION = ['auth', 'isRevisor', 'signed:relative'];

    public function test_every_site_route_is_public_on_purpose_or_protected(): void
    {
        $unprotected = collect(Router::getRoutes()->getRoutes())
            // Solo le rotte di routes/web.php: Fortify, Livewire, API dell'agente e /up hanno controlli propri
            ->filter(fn (Route $route) => str_starts_with((string) $route->getActionName(), 'App\\Http\\Controllers\\')
                && ! str_starts_with((string) $route->getActionName(), 'App\\Http\\Controllers\\Api\\')
                || $route->getActionName() === 'Closure')
            ->filter(fn (Route $route) => in_array('web', $route->gatherMiddleware(), true))
            ->reject(fn (Route $route) => in_array($route->uri(), self::PUBLIC_URIS, true))
            ->reject(fn (Route $route) => array_intersect(self::PROTECTION, $route->gatherMiddleware()) !== [])
            ->map(fn (Route $route) => implode('|', $route->methods()).' '.$route->uri())
            ->values()
            ->all();

        $this->assertSame([], $unprotected, 'Rotte senza auth, isRevisor o firma e non dichiarate pubbliche');
    }

    public function test_guests_are_sent_to_login_from_private_pages(): void
    {
        foreach (['/annunci/nuovo', '/profilo', '/carrello', '/ordini', '/vendite'] as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        $this->get('/revisore')->assertRedirect(route('homepage'));
    }

    public function test_old_urls_redirect_permanently_keeping_parameters_and_filters(): void
    {
        $user = User::factory()->create();
        $category = Category::factory()->create();
        $article = Article::factory()->accepted()->for($user)->for($category)->create();

        $moved = [
            '/article/index?page=2&sort=price_asc' => route('article.index', ['page' => 2, 'sort' => 'price_asc']),
            '/search/article?query=bici&category=3' => route('article.index', ['query' => 'bici', 'category' => 3]),
            '/create/article' => route('create.article'),
            "/show/article/{$article->id}" => route('article.show', $article),
            "/article/{$article->id}/edit" => route('article.edit', $article),
            "/category/{$category->id}?page=2" => route('byCategory', ['category' => $category, 'page' => 2]),
            "/venditore/{$user->id}" => route('sellers.show', $user),
            '/revisor/index' => route('revisor.index'),
        ];

        foreach ($moved as $old => $new) {
            $this->get($old)->assertStatus(301)->assertRedirect($new);
        }
    }

    public function test_new_urls_are_in_italian(): void
    {
        $user = User::factory()->create();
        $article = Article::factory()->accepted()->create();

        $this->assertSame(url('/annunci'), route('article.index'));
        $this->assertSame(url('/annunci/nuovo'), route('create.article'));
        $this->assertSame(url("/annunci/{$article->id}"), route('article.show', $article));
        $this->assertSame(url("/annunci/{$article->id}/modifica"), route('article.edit', $article));
        $this->assertSame(url("/categorie/{$article->category_id}"), route('byCategory', $article->category_id));
        $this->assertSame(url("/venditori/{$user->id}"), route('sellers.show', $user));
        $this->assertSame(url('/revisore'), route('revisor.index'));
        $this->assertSame(url("/revisore/annunci/{$article->id}/accetta"), route('accept', $article));
    }
}
