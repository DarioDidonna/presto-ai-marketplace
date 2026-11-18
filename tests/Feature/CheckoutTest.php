<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\PurchaseRequested;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CheckoutTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $giulia;

    private User $marco;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buyer = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);
        $this->giulia = User::factory()->create(['name' => 'Giulia', 'locale' => 'en']);
        $this->marco = User::factory()->create(['name' => 'Marco']);
    }

    private function fillCart(): array
    {
        $articles = [
            Article::factory()->accepted()->for($this->giulia)->create(['title' => 'Lampada', 'price' => 65]),
            Article::factory()->accepted()->for($this->giulia)->create(['title' => 'Sedie', 'price' => 180]),
            Article::factory()->accepted()->for($this->marco)->create(['title' => 'Casco', 'price' => 85.5]),
        ];
        $this->buyer->cart()->attach(collect($articles)->pluck('id'));

        return $articles;
    }

    public function test_checkout_creates_one_request_per_seller_and_reserves_the_articles(): void
    {
        $articles = $this->fillCart();

        $this->actingAs($this->buyer)->post(route('cart.checkout'), ['message' => 'Posso ritirare sabato'])
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('message', trans_choice('ui.checkout_done', 2, ['count' => 2]));

        $requests = PurchaseRequest::with('items')->orderBy('seller_id')->get();
        $this->assertCount(2, $requests);
        $this->assertSame([$this->giulia->id, $this->marco->id], $requests->pluck('seller_id')->all());
        $this->assertSame(['245.00', '85.50'], $requests->pluck('total')->all());
        $this->assertSame(['Lampada', 'Sedie'], $requests[0]->items->pluck('title')->all());
        $this->assertTrue($requests->every(fn ($r) => $r->status === PurchaseRequest::PENDING && $r->message === 'Posso ritirare sabato'));

        foreach ($articles as $article) {
            $this->assertTrue($article->fresh()->isReserved());
        }
        $this->assertSame(0, $this->buyer->cart()->count());
    }

    public function test_each_seller_is_notified_in_their_language(): void
    {
        $this->fillCart();
        $this->actingAs($this->buyer)->post(route('cart.checkout'));

        Notification::assertSentTo($this->giulia, PurchaseRequested::class, fn ($n, $channels, $notifiable, $locale) => $locale === 'en');
        Notification::assertSentTo($this->marco, PurchaseRequested::class);
        Notification::assertNotSentTo($this->buyer, PurchaseRequested::class);
    }

    public function test_seller_email_lists_items_message_and_buyer_contact(): void
    {
        $this->fillCart();
        $this->actingAs($this->buyer)->post(route('cart.checkout'), ['message' => 'Posso ritirare sabato']);

        $request = PurchaseRequest::where('seller_id', $this->giulia->id)->first();
        $mail = (new PurchaseRequested($request))->toMail($this->giulia);
        $text = implode("\n", $mail->introLines);

        $this->assertSame(__('ui.purchase_mail_subject', ['name' => 'Anna']), $mail->subject);
        $this->assertStringContainsString('Lampada — 65,00 €', $text);
        $this->assertStringContainsString('Posso ritirare sabato', $text);
        $this->assertStringContainsString('anna@example.com', $text);
        $this->assertStringNotContainsString('Casco', $text); // gli oggetti di un altro venditore non compaiono
    }

    public function test_empty_cart_cannot_be_sent(): void
    {
        $this->actingAs($this->buyer)->post(route('cart.checkout'))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('warning', __('ui.checkout_empty'));

        $this->assertSame(0, PurchaseRequest::count());
    }

    public function test_nothing_is_sent_if_an_item_was_reserved_meanwhile(): void
    {
        [$lamp, $chairs] = $this->fillCart();
        $lamp->forceFill(['reserved_at' => now()])->save(); // un altro acquirente è arrivato prima

        $this->actingAs($this->buyer)->post(route('cart.checkout'))
            ->assertRedirect(route('cart.index'))
            ->assertSessionHas('warning', fn ($message) => str_contains($message, 'Lampada'));

        $this->assertSame(0, PurchaseRequest::count());
        $this->assertNull($chairs->fresh()->reserved_at);
        $this->assertSame(2, $this->buyer->cart()->count()); // tolto solo l'oggetto non più disponibile
        Notification::assertNothingSent();
    }

    public function test_double_submit_creates_the_requests_only_once(): void
    {
        $this->fillCart();

        $this->actingAs($this->buyer)->post(route('cart.checkout'));
        $this->actingAs($this->buyer)->post(route('cart.checkout'))->assertSessionHas('warning', __('ui.checkout_empty'));

        $this->assertSame(2, PurchaseRequest::count());
    }

    public function test_message_is_validated(): void
    {
        $this->fillCart();

        $this->actingAs($this->buyer)->post(route('cart.checkout'), ['message' => str_repeat('a', 501)])
            ->assertSessionHasErrors('message');
        $this->assertSame(0, PurchaseRequest::count());
    }

    public function test_reserved_articles_cannot_be_added_and_leave_other_carts(): void
    {
        $article = Article::factory()->accepted()->create(['title' => 'Bici']);
        $other = User::factory()->create();
        $other->cart()->attach($article->id);
        $this->buyer->cart()->attach($article->id);

        $this->actingAs($this->buyer)->post(route('cart.checkout'));

        $this->actingAs(User::factory()->create())->post(route('cart.add', $article))->assertForbidden();
        $this->actingAs($other)->get(route('cart.index'))
            ->assertSee(trans_choice('ui.cart_items_removed', 1, ['count' => 1]));
        $this->assertSame(0, $other->cart()->count());
    }

    public function test_reserved_label_in_listing_and_detail_page(): void
    {
        $article = Article::factory()->accepted()->create(['reserved_at' => now()]);

        $this->get(route('articles.index'))->assertSee(__('ui.reserved'));
        $this->actingAs($this->buyer)->get(route('articles.show', $article))
            ->assertSee(__('ui.reserved_notice'))
            ->assertDontSee(__('ui.cart_add'));
    }

    public function test_orders_page_shows_only_own_orders(): void
    {
        $this->fillCart();
        $this->actingAs($this->buyer)->post(route('cart.checkout'), ['message' => 'Ciao!']);
        $stranger = User::factory()->create();

        $this->actingAs($this->buyer)->get(route('orders.index'))
            ->assertOk()
            ->assertSee(__('ui.cart_seller', ['name' => 'Giulia']))
            ->assertSee('Lampada')
            ->assertSee(__('ui.order_status_pending'))
            ->assertSee('Ciao!');

        $this->actingAs($stranger)->get(route('orders.index'))->assertSee(__('ui.orders_empty'))->assertDontSee('Lampada');
        auth()->logout();
        $this->get(route('orders.index'))->assertRedirect(route('login'));
    }

    public function test_order_history_survives_article_deletion(): void
    {
        [$lamp] = $this->fillCart();
        $this->actingAs($this->buyer)->post(route('cart.checkout'));

        $lamp->delete();

        $this->actingAs($this->buyer)->get(route('orders.index'))->assertSee('Lampada')->assertSee('65,00 €');
    }
}
