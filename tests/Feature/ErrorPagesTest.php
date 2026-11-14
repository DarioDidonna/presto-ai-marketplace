<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_url_shows_themed_404_in_italian(): void
    {
        $this->get('/pagina-che-non-esiste', ['Accept-Language' => 'it-IT,it;q=0.9'])
            ->assertNotFound()
            ->assertSee('<title>404 · Pagina non trovata · Presto.it</title>', false)
            ->assertSee('PRESTO<span class="text-neon-cyan">.</span>IT', false)
            ->assertSee(__('ui.all_ads_title', [], 'it'));
    }

    public function test_404_respects_the_language_chosen_by_the_user(): void
    {
        // Browser in una lingua non supportata: italiano, come il resto del sito
        $this->get('/pagina-che-non-esiste', ['Accept-Language' => 'de-DE,de;q=0.9'])->assertSee('Pagina non trovata');

        // URL inesistente: niente sessione, vale la lingua del browser
        $this->get('/pagina-che-non-esiste', ['Accept-Language' => 'en-GB,en;q=0.9'])
            ->assertNotFound()
            ->assertSee('Page not found');

        $this->withSession(['locale' => 'es'])->get('/annunci/999')
            ->assertNotFound()
            ->assertSee('Página no encontrada');
    }

    public function test_error_pages_do_not_query_the_database(): void
    {
        DB::enableQueryLog();
        $this->get('/pagina-che-non-esiste')->assertNotFound();

        $queries = array_column(DB::getQueryLog(), 'query');
        $this->assertSame([], array_values(array_filter($queries, fn ($q) => ! str_contains($q, '"sessions"'))));
    }

    public function test_403_when_editing_someone_elses_ad(): void
    {
        $article = Article::factory()->accepted()->create();

        $this->actingAs(User::factory()->create())->get(route('article.edit', $article))
            ->assertForbidden()
            ->assertSee(__('ui.error_403_title'));
    }

    public function test_429_when_too_many_requests(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        foreach (range(1, 3) as $n) {
            $this->actingAs($user)->post(route('become.revisor'));
        }

        $this->actingAs($user)->post(route('become.revisor'))
            ->assertStatus(429)
            ->assertSee(__('ui.error_429_title'));
    }

    public function test_419_500_and_503_pages(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_test/419', fn () => throw new TokenMismatchException);
        Route::middleware('web')->get('/_test/500', fn () => throw new \RuntimeException('Errore interno di prova'));
        Route::middleware('web')->get('/_test/503', fn () => abort(503));

        $this->get('/_test/419')->assertStatus(419)->assertSee(__('ui.error_419_title'));
        $this->get('/_test/503')->assertStatus(503)->assertSee(__('ui.error_503_title'));
        $this->get('/_test/500')
            ->assertStatus(500)
            ->assertSee(__('ui.error_500_title'))
            ->assertDontSee('Errore interno di prova'); // nessun dettaglio tecnico mostrato all'utente
    }
}
