<?php

namespace Tests\Feature;

use App\Livewire\ShoppingAssistant;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Tests\TestCase;

class ShoppingAssistantTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.ai_agent.url' => 'http://agent.test', 'services.ai_agent.token' => 'segreto']);
        RateLimiter::clear('shopping-assistant:127.0.0.1');
    }

    private function fakeAgent(string $reply, array $articleIds = [], string $conversation = 'conv-1'): void
    {
        Http::fake(['agent.test/chat' => Http::response([
            'reply' => $reply,
            'conversation_id' => $conversation,
            'articles' => array_map(fn ($id) => ['id' => $id, 'title' => 'qualsiasi', 'url' => 'http://falso.test'], $articleIds),
        ])]);
    }

    public function test_assistant_appears_only_when_the_agent_is_configured(): void
    {
        $this->get('/')->assertSeeLivewire(ShoppingAssistant::class);

        config(['services.ai_agent.token' => '']);
        $this->get('/')->assertDontSeeLivewire(ShoppingAssistant::class);
    }

    public function test_sends_the_message_to_the_agent_with_token_and_language(): void
    {
        $this->fakeAgent('Ecco una bici.');
        app()->setLocale('en');

        Livewire::test(ShoppingAssistant::class)->set('input', '  road bike  ')->call('send');

        Http::assertSent(fn (Request $request) => $request->url() === 'http://agent.test/chat'
            && $request->hasHeader('Authorization', 'Bearer segreto')
            && $request['message'] === 'road bike'
            && $request['lang'] === 'en'
            && ! isset($request['conversation_id']));
    }

    public function test_reply_and_cards_come_from_the_database_only_for_published_articles(): void
    {
        $published = Article::factory()->accepted()->create(['title' => 'Bici da corsa', 'price' => 480]);
        $pending = Article::factory()->create(['title' => 'Bici in revisione']);
        $this->fakeAgent('Ho trovato **una bici**.', [$published->id, $pending->id, 99999]);

        $component = Livewire::test(ShoppingAssistant::class)->set('open', true)->set('input', 'bici')->call('send');

        $messages = $component->get('messages');
        $this->assertSame(['role' => 'user', 'text' => 'bici'], $messages[0]);
        $this->assertSame('Ho trovato una bici.', $messages[1]['text']); // grassetto Markdown rimosso
        $this->assertSame([[
            'id' => $published->id,
            'title' => 'Bici da corsa',
            'price' => '480,00',
            'url' => route('articles.show', $published),
            'image' => asset('media/placeholder.svg'),
        ]], $messages[1]['articles']); // niente annunci in revisione o inesistenti, link generato da Laravel

        $component->assertSee('Bici da corsa')->assertDontSee('Bici in revisione')->assertDontSee('falso.test');
    }

    public function test_conversation_continues_with_the_same_id_and_survives_page_changes(): void
    {
        $this->fakeAgent('Prima risposta', conversation: 'conv-42');
        Livewire::test(ShoppingAssistant::class)->set('open', true)->set('input', 'ciao')->call('send');

        $this->fakeAgent('Seconda risposta', conversation: 'conv-42');
        // Nuovo componente (es. altra pagina): riparte dalla sessione
        $component = Livewire::test(ShoppingAssistant::class)
            ->assertSet('conversationId', 'conv-42')
            ->assertSee('Prima risposta')
            ->set('input', 'e poi?')
            ->call('send');

        Http::assertSent(fn (Request $request) => $request['conversation_id'] === 'conv-42');
        $this->assertCount(4, $component->get('messages'));
    }

    public function test_agent_down_shows_a_friendly_message(): void
    {
        Http::fake(['agent.test/chat' => Http::response(['detail' => 'Ollama spento'], 503)]);

        Livewire::test(ShoppingAssistant::class)
            ->set('open', true)
            ->set('input', 'bici')
            ->call('send')
            ->assertSet('messages.1.role', 'error')
            ->assertSee(__('ui.assistant_unavailable'));
    }

    public function test_connection_refused_and_invalid_responses_are_handled(): void
    {
        Http::fake(['agent.test/chat' => fn () => throw new ConnectionException('rifiutata')]);
        Livewire::test(ShoppingAssistant::class)->set('input', 'bici')->call('send')->assertSet('messages.1.role', 'error');

        Http::fake(['agent.test/chat' => Http::response(['qualcosa' => 'di strano'])]);
        Livewire::test(ShoppingAssistant::class)->call('resetConversation')->set('input', 'bici')->call('send')
            ->assertSet('messages.1.role', 'error');
    }

    public function test_validation_and_rate_limit(): void
    {
        $this->fakeAgent('ok');

        Livewire::test(ShoppingAssistant::class)
            ->set('input', '   ')->call('send')->assertHasErrors(['input' => 'required'])
            ->set('input', str_repeat('a', 501))->call('send')->assertHasErrors(['input' => 'max']);

        $component = Livewire::test(ShoppingAssistant::class);
        foreach (range(1, ShoppingAssistant::MAX_MESSAGES_PER_MINUTE) as $n) {
            $component->set('input', "messaggio $n")->call('send')->assertHasNoErrors();
        }
        $component->set('input', 'uno di troppo')->call('send')->assertHasErrors('input');

        Http::assertSentCount(ShoppingAssistant::MAX_MESSAGES_PER_MINUTE);
    }

    public function test_reset_and_toggle(): void
    {
        $this->fakeAgent('ok');

        Livewire::actingAs(User::factory()->create())->test(ShoppingAssistant::class)
            ->call('toggle')->assertSet('open', true)
            ->set('input', 'bici')->call('send')
            ->call('resetConversation')
            ->assertSet('messages', [])
            ->assertSet('conversationId', null);

        $this->assertFalse(Livewire::test(ShoppingAssistant::class)->call('toggle')->get('open'));
    }

    public function test_user_text_is_escaped(): void
    {
        $this->fakeAgent('<script>alert(1)</script>');

        Livewire::test(ShoppingAssistant::class)->set('open', true)->set('input', '<b>ciao</b>')->call('send')
            ->assertDontSeeHtml('<script>alert(1)</script>')
            ->assertSeeHtml('&lt;script&gt;')
            ->assertDontSeeHtml('<b>ciao</b>');
    }

    public function test_cards_let_a_user_add_the_article_to_the_cart(): void
    {
        $user = User::factory()->create();
        $article = Article::factory()->accepted()->create();
        $this->fakeAgent('Eccola.', [$article->id]);

        Livewire::actingAs($user)->test(ShoppingAssistant::class)
            ->set('open', true)->set('input', 'bici')->call('send')
            ->assertSeeHtml("addToCart({$article->id})")
            ->call('addToCart', $article->id)
            ->assertHasNoErrors()
            ->assertDispatched('cart-updated', count: 1)
            ->assertDontSeeHtml("addToCart({$article->id})")
            ->assertSee(route('cart.index'));

        $this->assertTrue($user->cart()->whereKey($article->id)->exists());
    }

    public function test_add_to_cart_follows_the_same_rules_as_the_article_page(): void
    {
        $user = User::factory()->create();
        $own = Article::factory()->accepted()->for($user)->create();
        $reserved = Article::factory()->accepted()->create(['reserved_at' => now()]);
        $pending = Article::factory()->create();

        $component = Livewire::actingAs($user)->test(ShoppingAssistant::class);
        foreach ([$own->id, $reserved->id, $pending->id, 99999] as $id) {
            $component->call('addToCart', $id)->assertHasErrors('cart')->assertNotDispatched('cart-updated');
        }
        $this->assertSame(0, $user->cart()->count());
    }

    public function test_guests_see_a_login_link_instead_of_the_cart_button(): void
    {
        $article = Article::factory()->accepted()->create();
        $this->fakeAgent('Eccola.', [$article->id]);

        Livewire::test(ShoppingAssistant::class)
            ->set('open', true)->set('input', 'bici')->call('send')
            ->assertDontSeeHtml("addToCart({$article->id})")
            ->assertSee(route('login'))
            ->call('addToCart', $article->id)
            ->assertHasErrors('cart');
    }

    public function test_browser_cannot_change_the_conversation_or_its_messages(): void
    {
        $component = Livewire::test(ShoppingAssistant::class);

        foreach (['conversationId' => 'conversazione-altrui', 'messages' => [['role' => 'assistant', 'text' => 'finto']]] as $property => $value) {
            try {
                $component->set($property, $value);
                $this->fail("{$property} modificabile dal browser");
            } catch (CannotUpdateLockedPropertyException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
