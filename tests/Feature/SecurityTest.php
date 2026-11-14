<?php

namespace Tests\Feature;

use App\Http\Middleware\SecurityHeaders;
use App\Mail\BecomeRevisor;
use App\Models\Article;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Notifications\NewMessage;
use App\Notifications\QueuedResetPassword;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class SecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_and_normal_user_cannot_accept_or_reject(): void
    {
        $article = Article::factory()->create();

        $this->patch(route('accept', $article))->assertRedirect(route('homepage'));
        $this->patch(route('reject', $article))->assertRedirect(route('homepage'));
        $this->actingAs(User::factory()->create())->patch(route('accept', $article))->assertRedirect(route('homepage'));

        $this->assertNull($article->fresh()->is_accepted);
    }

    public function test_normal_user_cannot_open_revisor_dashboard(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('revisor.index'))
            ->assertRedirect(route('homepage'))
            ->assertSessionHas('errorMessage');
    }

    public function test_make_revisor_requires_valid_signature(): void
    {
        $user = User::factory()->create();

        $this->get(route('make.revisor', $user))->assertForbidden();
        $this->post(route('make.revisor.store', $user))->assertForbidden();
        $this->assertFalse((bool) $user->fresh()->is_revisor);

        $expired = URL::temporarySignedRoute('make.revisor', now()->subMinute(), ['user' => $user], absolute: false);
        $this->get($expired)->assertForbidden();
        $this->post($expired)->assertForbidden();
        $this->assertFalse((bool) $user->fresh()->is_revisor);
    }

    public function test_opening_the_link_does_not_promote_and_confirming_works_once(): void
    {
        $user = User::factory()->create();
        $url = URL::temporarySignedRoute('make.revisor', now()->addDay(), ['user' => $user], absolute: false);

        // Un antivirus di posta che apre il link non deve promuovere nessuno
        $this->get($url)->assertOk()->assertSee($user->name)->assertSee('action="'.e($url).'"', false);
        $this->assertFalse((bool) $user->fresh()->is_revisor);

        $this->post($url)->assertRedirect(route('homepage'));
        $this->assertTrue((bool) $user->fresh()->is_revisor);

        // Monouso: se il revisore viene poi revocato, lo stesso link non lo riattiva
        $user->forceFill(['is_revisor' => false])->save();
        $this->post($url)->assertForbidden();
        $this->assertFalse((bool) $user->fresh()->is_revisor);
    }

    public function test_signed_link_cannot_be_reused_for_another_user(): void
    {
        $victim = User::factory()->create();
        $attacker = User::factory()->create();
        $url = URL::temporarySignedRoute('make.revisor', now()->addDay(), ['user' => $victim], absolute: false);

        $this->post(str_replace("/{$victim->id}?", "/{$attacker->id}?", $url))->assertForbidden();
        $this->assertFalse((bool) $attacker->fresh()->is_revisor);
    }

    public function test_revisor_link_ignores_a_forged_host_header(): void
    {
        Mail::fake();
        config(['app.url' => 'https://presto.test']);
        $user = User::factory()->create();

        $this->actingAs($user)->post('http://evil.example/revisore/candidatura');

        Mail::assertQueued(BecomeRevisor::class, fn (BecomeRevisor $mail) => str_starts_with($mail->approvalUrl, 'https://presto.test/revisore/conferma/'));
    }

    public function test_become_revisor_requires_post_auth_and_is_throttled(): void
    {
        Mail::fake();
        $user = User::factory()->create();

        $this->get('/revisore/candidatura')->assertStatus(405);
        $this->post(route('become.revisor'))->assertRedirect(route('login'));

        foreach (range(1, 3) as $n) {
            $this->actingAs($user)->post(route('become.revisor'))->assertRedirect(route('homepage'));
        }
        $this->actingAs($user)->post(route('become.revisor'))->assertStatus(429);

        Mail::assertQueued(BecomeRevisor::class, 3);
    }

    public function test_unapproved_article_is_visible_only_to_author_and_revisors(): void
    {
        foreach ([Article::factory()->create(), Article::factory()->rejected()->create()] as $article) {
            $this->app['auth']->forgetGuards();
            $this->get(route('article.show', $article))->assertNotFound();
            $this->actingAs(User::factory()->create())->get(route('article.show', $article))->assertNotFound();
            $this->actingAs($article->user)->get(route('article.show', $article))->assertOk();
            $this->actingAs(User::factory()->revisor()->create())->get(route('article.show', $article))->assertOk();
        }
    }

    public function test_invalid_locale_is_rejected(): void
    {
        $this->post('/lingua/xx')->assertNotFound();
        $this->post('/lingua/..%2F..%2Fetc')->assertNotFound();

        $this->post('/lingua/es')->assertRedirect();
        $this->assertSame('es', session('locale'));
    }

    public function test_tampered_session_locale_falls_back_to_italian(): void
    {
        $this->withSession(['locale' => 'xx'])->get('/')->assertOk()->assertSee('lang="it"', false);
    }

    public function test_reset_link_ignores_a_forged_host_header(): void
    {
        Notification::fake();
        config(['app.url' => 'https://presto.test']);
        $user = User::factory()->create();

        $this->post('http://evil.example/forgot-password', ['email' => $user->email]);

        Notification::assertSentTo($user, QueuedResetPassword::class, function (QueuedResetPassword $notification) use ($user) {
            return str_starts_with($notification->toMail($user)->actionUrl, 'https://presto.test/reset-password/');
        });
    }

    public function test_forged_host_is_rejected_in_production(): void
    {
        config(['app.url' => 'https://presto.test']);
        $this->app['env'] = 'production';

        try {
            $this->get('https://presto.test/up')->assertOk();
            $this->get('http://evil.example/up')->assertStatus(400);
        } finally {
            // Regola statica di Symfony: non deve restare attiva per i test successivi
            Request::setTrustedHosts([]);
        }
    }

    public function test_revisor_cannot_judge_their_own_article(): void
    {
        $revisor = User::factory()->revisor()->create();
        $own = Article::factory()->create(['user_id' => $revisor->id]);

        $this->actingAs($revisor)->patch(route('accept', $own))->assertForbidden();
        $this->actingAs($revisor)->patch(route('reject', $own))->assertForbidden();
        $this->assertNull($own->fresh()->is_accepted);

        $this->actingAs($revisor)->get(route('revisor.index'))->assertDontSee($own->title);

        $other = Article::factory()->create();
        $this->actingAs($revisor)->get(route('revisor.index'))->assertSee($other->title);
        $this->actingAs($revisor)->patch(route('accept', $other))->assertRedirect();
        $this->assertTrue($other->fresh()->is_accepted);
    }

    public function test_markdown_written_by_users_does_not_become_a_link_in_emails(): void
    {
        $trap = '[Conferma il pagamento](https://evil.example/x)';
        $sender = User::factory()->create(['name' => $trap]);
        $request = PurchaseRequest::factory()->create(['buyer_id' => $sender->id]);
        $message = $request->messages()->create(['user_id' => $sender->id, 'body' => "Ciao {$trap} **urgente** <b>x</b>"]);

        $html = (string) (new NewMessage($message))->toMail($request->seller)->render();

        $this->assertStringNotContainsString('href="https://evil.example', $html);
        $this->assertStringNotContainsString('<strong>urgente', $html);
        $this->assertStringNotContainsString('<b>x</b>', $html);
        $this->assertStringContainsString('[Conferma il pagamento](https://evil.example/x)', $html);
        $this->assertStringContainsString('**urgente**', $html);
    }

    public function test_security_headers_are_on_every_response(): void
    {
        foreach (['/', '/up', '/api/agent/categories'] as $url) {
            $this->get($url)
                ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('Content-Security-Policy', SecurityHeaders::CSP)
                ->assertHeaderMissing('Strict-Transport-Security');
        }
    }

    public function test_hsts_is_sent_only_in_production_over_https(): void
    {
        config(['app.url' => 'https://presto.test']);
        $this->app['env'] = 'production';

        try {
            $this->get('https://presto.test/up')->assertHeader('Strict-Transport-Security');
        } finally {
            Request::setTrustedHosts([]);
        }
    }

    public function test_passwords_are_stricter_in_production(): void
    {
        Http::fake(['api.pwnedpasswords.com/*' => Http::response('')]);
        $check = fn (string $password) => Validator::make(['password' => $password], ['password' => Password::default()])->passes();

        $this->assertTrue($check('Corta123'));

        $this->app['env'] = 'production';
        $this->assertFalse($check('Corta123'));
        $this->assertTrue($check('UnaPasswordLunga123'));
    }

    public function test_reverb_defaults_are_closed(): void
    {
        $app = config('reverb.apps.apps.0');

        $this->assertNotContains('*', $app['allowed_origins']);
        $this->assertTrue($app['rate_limiting']['enabled']);
        $this->assertSame('127.0.0.1', config('reverb.servers.reverb.host'));
    }

    public function test_reverb_accepts_only_the_site_and_in_local_also_127_0_0_1(): void
    {
        $origins = function (string $env, string $url) {
            [$_ENV['APP_ENV'], $_ENV['APP_URL'], $_SERVER['APP_ENV'], $_SERVER['APP_URL']] = [$env, $url, $env, $url];

            return (require config_path('reverb.php'))['apps']['apps'][0]['allowed_origins'];
        };
        [$env, $url] = [$_ENV['APP_ENV'] ?? 'testing', $_ENV['APP_URL'] ?? null];

        try {
            $this->assertSame(['localhost', '127.0.0.1'], $origins('local', 'http://localhost:8000'));
            $this->assertSame(['presto.test'], $origins('production', 'https://presto.test'));
        } finally {
            [$_ENV['APP_ENV'], $_SERVER['APP_ENV']] = [$env, $env];
            if ($url === null) {
                unset($_ENV['APP_URL'], $_SERVER['APP_URL']);
            } else {
                [$_ENV['APP_URL'], $_SERVER['APP_URL']] = [$url, $url];
            }
        }
    }

    public function test_demo_accounts_cannot_be_created_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(\RuntimeException::class);
        (new DemoSeeder)->run();
    }

    public function test_rate_limit_of_one_action_does_not_block_the_others(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => 'Password123!']);

        foreach (range(1, 5) as $n) {
            $this->actingAs($user)->get(route('profile.export'))->assertOk();
        }
        $this->actingAs($user)->get(route('profile.export'))->assertStatus(429);

        // Le altre azioni hanno il loro contatore
        $this->actingAs($user)->post(route('become.revisor'))->assertRedirect(route('homepage'));
        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'sbagliata'])
            ->assertSessionHasErrorsIn('deleteAccount', 'password');
    }
}
