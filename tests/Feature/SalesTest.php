<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\PurchaseRequestAnswered;
use App\Notifications\PurchaseRequestCancelled;
use App\Notifications\PurchaseRequested;
use App\Services\Checkout;
use App\Services\PurchaseRequestNotPendingException;
use App\Services\PurchaseRequestWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SalesTest extends TestCase
{
    use RefreshDatabase;

    private User $buyer;

    private User $seller;

    private Article $bike;

    private PurchaseRequest $request;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->buyer = User::factory()->create(['name' => 'Anna', 'email' => 'anna@example.com']);
        $this->seller = User::factory()->create(['name' => 'Giulia', 'email' => 'giulia@example.com']);
        $this->bike = Article::factory()->accepted()->for($this->seller)->create(['title' => 'Bici da corsa', 'price' => 480]);

        $this->buyer->cart()->attach($this->bike->id);
        $this->request = app(Checkout::class)->submit($this->buyer, 'Ritiro sabato')->first();
    }

    public function test_seller_sees_received_requests_with_buyer_contact(): void
    {
        $this->actingAs($this->seller)->get(route('sales.index'))
            ->assertOk()
            ->assertSee(__('ui.sales_buyer', ['name' => 'Anna']))
            ->assertSee('anna@example.com')
            ->assertSee('Ritiro sabato')
            ->assertSee(__('ui.sales_accept'))
            ->assertSee(__('ui.sales_decline'));

        $this->actingAs(User::factory()->create())->get(route('sales.index'))->assertSee(__('ui.sales_empty'))->assertDontSee('Anna');
    }

    public function test_seller_email_links_to_the_requests_page(): void
    {
        $mail = (new PurchaseRequested($this->request))->toMail($this->seller);

        $this->assertSame(route('sales.index'), $mail->actionUrl);
    }

    public function test_accepting_sells_the_articles_and_notifies_the_buyer(): void
    {
        $other = User::factory()->create();
        $other->cart()->attach($this->bike->id); // aggiunto prima che venisse riservato

        $this->actingAs($this->seller)->patch(route('sales.accept', $this->request))
            ->assertRedirect(route('sales.index'))
            ->assertSessionHas('message');

        $this->assertSame(PurchaseRequest::ACCEPTED, $this->request->fresh()->status);
        $bike = $this->bike->fresh();
        $this->assertTrue($bike->isSold());
        $this->assertFalse($bike->isReserved());
        $this->assertSame(0, $other->cart()->count());

        Notification::assertSentTo($this->buyer, PurchaseRequestAnswered::class, function ($notification) {
            $mail = $notification->toMail($this->buyer);

            return $notification->accepted && str_contains(implode(' ', $mail->introLines), 'giulia@example.com');
        });
    }

    public function test_sold_articles_leave_public_listings_but_keep_their_page(): void
    {
        app(PurchaseRequestWorkflow::class)->accept($this->request);

        $this->get(route('article.index'))->assertDontSee('Bici da corsa');
        $this->get('/annunci?query=bici')->assertDontSee('Bici da corsa');
        $this->get(route('byCategory', $this->bike->category))->assertDontSee('Bici da corsa');
        $this->get('/')->assertDontSee('Bici da corsa');

        $this->actingAs(User::factory()->create())->get(route('article.show', $this->bike))
            ->assertOk()
            ->assertSee(__('ui.sold_notice'))
            ->assertDontSee(__('ui.cart_add'));

        // Anche l'agente AI non lo propone più
        config(['services.ai_agent.token' => 'segreto']);
        $this->withToken('segreto')->getJson("/api/agent/articles/{$this->bike->id}")->assertNotFound();
        $this->withToken('segreto')->getJson('/api/agent/articles?query=bici')->assertJsonPath('total', 0);
    }

    public function test_buyer_sees_seller_contact_only_after_acceptance(): void
    {
        $this->actingAs($this->buyer)->get(route('orders.index'))->assertDontSee('giulia@example.com');

        app(PurchaseRequestWorkflow::class)->accept($this->request);

        $this->actingAs($this->buyer)->get(route('orders.index'))
            ->assertSee('giulia@example.com')
            ->assertSee(__('ui.order_status_accepted'));
    }

    public function test_declining_makes_the_articles_available_again(): void
    {
        $this->actingAs($this->seller)->patch(route('sales.decline', $this->request))->assertSessionHas('message');

        $this->assertSame(PurchaseRequest::DECLINED, $this->request->fresh()->status);
        $this->assertTrue($this->bike->fresh()->isAvailable());
        Notification::assertSentTo($this->buyer, PurchaseRequestAnswered::class, fn ($n) => $n->accepted === false);

        $this->actingAs(User::factory()->create())->post(route('cart.add', $this->bike))->assertRedirect();
    }

    public function test_buyer_can_cancel_a_pending_request(): void
    {
        $this->actingAs($this->buyer)->patch(route('orders.cancel', $this->request))
            ->assertRedirect(route('orders.index'))
            ->assertSessionHas('message', __('ui.orders_cancelled'));

        $this->assertSame(PurchaseRequest::CANCELLED, $this->request->fresh()->status);
        $this->assertTrue($this->bike->fresh()->isAvailable());
        Notification::assertSentTo($this->seller, PurchaseRequestCancelled::class);
    }

    public function test_only_the_right_person_can_act(): void
    {
        $stranger = User::factory()->create();

        $this->actingAs($this->buyer)->patch(route('sales.accept', $this->request))->assertForbidden();
        $this->actingAs($stranger)->patch(route('sales.decline', $this->request))->assertForbidden();
        $this->actingAs($this->seller)->patch(route('orders.cancel', $this->request))->assertForbidden();
        $this->actingAs($stranger)->patch(route('orders.cancel', $this->request))->assertForbidden();
        auth()->logout();
        $this->patch(route('sales.accept', $this->request))->assertRedirect(route('login'));

        $this->assertSame(PurchaseRequest::PENDING, $this->request->fresh()->status);
    }

    public function test_answered_requests_cannot_change_again(): void
    {
        $this->actingAs($this->seller)->patch(route('sales.decline', $this->request));

        $this->actingAs($this->seller)->patch(route('sales.accept', $this->request))->assertForbidden();
        $this->actingAs($this->buyer)->patch(route('orders.cancel', $this->request))->assertForbidden();
        $this->assertFalse($this->bike->fresh()->isSold());
    }

    public function test_simultaneous_accept_and_cancel_only_the_first_wins(): void
    {
        // Il venditore ha caricato la pagina quando la richiesta era ancora in attesa
        $stale = PurchaseRequest::find($this->request->id);
        app(PurchaseRequestWorkflow::class)->cancel($this->request);

        $this->expectException(PurchaseRequestNotPendingException::class);
        try {
            app(PurchaseRequestWorkflow::class)->accept($stale);
        } finally {
            $this->assertSame(PurchaseRequest::CANCELLED, $stale->fresh()->status);
            $this->assertFalse($this->bike->fresh()->isSold());
        }
    }

    public function test_navbar_shows_pending_requests_to_the_seller(): void
    {
        $this->actingAs($this->seller)->get('/')
            ->assertSee(trans_choice('ui.sales_pending_count', 1, ['count' => 1]))
            ->assertSee(route('sales.index'), false);

        app(PurchaseRequestWorkflow::class)->accept($this->request);

        $this->actingAs($this->seller)->get('/')->assertDontSee(trans_choice('ui.sales_pending_count', 1, ['count' => 1]));
    }

    public function test_profile_shows_sold_and_reserved_labels(): void
    {
        $this->actingAs($this->seller)->get(route('profile.show'))->assertSee(__('ui.reserved'));

        app(PurchaseRequestWorkflow::class)->accept($this->request);

        $this->actingAs($this->seller)->get(route('profile.show'))->assertSee(__('ui.sold'));
    }
}
