<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CartTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buyer = User::factory()->create();
    }

    public function test_guests_are_sent_to_login(): void
    {
        $article = Article::factory()->accepted()->create();

        $this->get(route('cart.index'))->assertRedirect(route('login'));
        $this->post(route('cart.add', $article))->assertRedirect(route('login'));
        $this->delete(route('cart.remove', $article))->assertRedirect(route('login'));
    }

    public function test_add_a_published_article_only_once(): void
    {
        $article = Article::factory()->accepted()->create(['title' => 'Bici da corsa']);

        $this->actingAs($this->buyer)->from(route('articles.show', $article))->post(route('cart.add', $article))
            ->assertRedirect(route('articles.show', $article))
            ->assertSessionHas('message', __('ui.cart_added', ['title' => 'Bici da corsa']));
        $this->actingAs($this->buyer)->post(route('cart.add', $article));

        $this->assertSame([$article->id], $this->buyer->cart()->pluck('articles.id')->all());
    }

    public function test_cannot_add_own_or_unpublished_articles(): void
    {
        $own = Article::factory()->accepted()->for($this->buyer)->create();

        foreach ([$own, Article::factory()->create(), Article::factory()->rejected()->create()] as $article) {
            $this->actingAs($this->buyer)->post(route('cart.add', $article))->assertForbidden();
        }

        $this->assertSame(0, $this->buyer->cart()->count());
    }

    public function test_remove_and_clear(): void
    {
        [$first, $second] = Article::factory()->count(2)->accepted()->create();
        $this->buyer->cart()->attach([$first->id, $second->id]);

        $this->actingAs($this->buyer)->delete(route('cart.remove', $first))->assertSessionHas('message');
        $this->assertSame([$second->id], $this->buyer->cart()->pluck('articles.id')->all());

        $this->actingAs($this->buyer)->delete(route('cart.clear'))->assertRedirect(route('cart.index'));
        $this->assertSame(0, $this->buyer->cart()->count());
    }

    public function test_cart_page_groups_articles_by_seller_with_totals(): void
    {
        $giulia = User::factory()->create(['name' => 'Giulia']);
        $marco = User::factory()->create(['name' => 'Marco']);
        $this->buyer->cart()->attach([
            Article::factory()->accepted()->for($giulia)->create(['title' => 'Lampada', 'price' => 65])->id,
            Article::factory()->accepted()->for($giulia)->create(['title' => 'Sedie', 'price' => 180])->id,
            Article::factory()->accepted()->for($marco)->create(['title' => 'Casco', 'price' => 85.5])->id,
        ]);

        $response = $this->actingAs($this->buyer)->get(route('cart.index'))->assertOk();

        $groups = $response->viewData('groups');
        $this->assertCount(2, $groups);
        $this->assertEqualsCanonicalizing([245.0, 85.5], $groups->pluck('subtotal')->all());
        $this->assertSame(330.5, $response->viewData('total'));
        $response->assertSee(__('ui.cart_seller', ['name' => 'Giulia']))
            ->assertSee('330,50 €')
            ->assertSee(trans_choice('ui.cart_count', 3, ['count' => 3]));
    }

    public function test_unavailable_articles_are_removed_with_a_warning(): void
    {
        $available = Article::factory()->accepted()->create(['title' => 'Ancora in vendita']);
        $edited = Article::factory()->accepted()->create(['title' => 'Modificato']);
        $this->buyer->cart()->attach([$available->id, $edited->id]);

        // L'autore modifica l'annuncio: torna in revisione e non è più acquistabile
        $edited->is_accepted = null;
        $edited->save();

        $this->actingAs($this->buyer)->get(route('cart.index'))
            ->assertSee('Ancora in vendita')
            ->assertDontSee('Modificato')
            ->assertSee(trans_choice('ui.cart_items_removed', 1, ['count' => 1]));

        $this->assertSame([$available->id], $this->buyer->cart()->pluck('articles.id')->all());
    }

    public function test_deleted_articles_disappear_from_carts(): void
    {
        // Senza il disco finto l'eliminazione cancellava davvero storage/app/public/articles/{id}
        Storage::fake('public');
        $article = Article::factory()->accepted()->create();
        $this->buyer->cart()->attach($article->id);

        $this->actingAs($article->user)->delete(route('articles.destroy', $article));

        $this->assertSame(0, $this->buyer->cart()->count());
    }

    public function test_empty_cart_page(): void
    {
        $this->actingAs($this->buyer)->get(route('cart.index'))->assertOk()->assertSee(__('ui.cart_empty'));
    }

    public function test_navbar_shows_the_number_of_items(): void
    {
        $this->buyer->cart()->attach(Article::factory()->count(2)->accepted()->create()->modelKeys());

        $this->actingAs($this->buyer)->get('/')
            ->assertSee(route('cart.index'), false)
            ->assertSee('cart-badge">2</span>', false);
    }

    public function test_article_page_buttons(): void
    {
        $article = Article::factory()->accepted()->create();

        $this->actingAs($this->buyer)->get(route('articles.show', $article))->assertSee(__('ui.cart_add'));

        $this->buyer->cart()->attach($article->id);
        $this->actingAs($this->buyer)->get(route('articles.show', $article))
            ->assertSee(__('ui.cart_in'))
            ->assertDontSee(__('ui.cart_add'));

        // Né l'autore né un ospite vedono il pulsante
        $this->actingAs($article->user)->get(route('articles.show', $article))->assertDontSee(__('ui.cart_add'));
        auth()->logout();
        $this->get(route('articles.show', $article))->assertDontSee(__('ui.cart_add'));
    }
}
