<?php

namespace Tests\Feature;

use App\Livewire\CreateArticleForm;
use App\Models\Article;
use App\Models\User;
use App\Notifications\QueuedResetPassword;
use App\Notifications\QueuedVerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Queue\Middleware\RateLimited;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_and_is_not_revisor(): void
    {
        $this->post('/register', [
            'name' => 'Mario Rossi',
            'email' => 'mario@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertRedirect('/annunci/nuovo');

        $this->assertAuthenticated();
        $user = User::where('email', 'mario@example.com')->first();
        $this->assertFalse((bool) $user->is_revisor);
    }

    public function test_registration_cannot_set_is_revisor(): void
    {
        $this->post('/register', [
            'name' => 'Furbo',
            'email' => 'furbo@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'is_revisor' => 1,
        ]);

        $this->assertFalse((bool) User::where('email', 'furbo@example.com')->first()->is_revisor);
    }

    public function test_user_can_login_and_logout(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'password'])->assertRedirect('/annunci/nuovo');
        $this->assertAuthenticatedAs($user);

        $this->post('/logout')->assertRedirect('/');
        $this->assertGuest();
    }

    public function test_wrong_password_is_rejected_and_throttled(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $attempt) {
            $this->post('/login', ['email' => $user->email, 'password' => 'sbagliata'])->assertSessionHasErrors('email');
        }
        $this->post('/login', ['email' => $user->email, 'password' => 'sbagliata'])->assertStatus(429);
        $this->assertGuest();
    }

    public function test_password_reset_pages_render_instead_of_500(): void
    {
        $this->get('/forgot-password')->assertOk()->assertSee(__('ui.forgot_password_title'));
        $this->get('/reset-password/token-di-prova?email=mario@example.com')
            ->assertOk()
            ->assertSee('value="token-di-prova"', false)
            ->assertSee('value="mario@example.com"', false);
        $this->actingAs(User::factory()->create())->get('/user/confirm-password')->assertOk();
    }

    public function test_reset_link_email_is_sent_and_translated(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])
            ->assertRedirect('/forgot-password')
            ->assertSessionHas('status', 'Ti abbiamo inviato via email il link per reimpostare la password.');

        Notification::assertSentTo($user, QueuedResetPassword::class,
            function ($notification) use ($user) {
                $mail = $notification->toMail($user);

                return $mail->subject === 'Reimposta la tua password' && $mail->actionText === 'Reimposta password';
            });
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NuovissimaPassword1!',
            'password_confirmation' => 'NuovissimaPassword1!',
        ])->assertRedirect(route('login'))->assertSessionHas('status', 'La tua password è stata reimpostata.');

        $this->assertTrue(Hash::check('NuovissimaPassword1!', $user->fresh()->password));
    }

    public function test_invalid_reset_token_is_rejected(): void
    {
        $user = User::factory()->create();

        $this->from('/reset-password/falso')->post('/reset-password', [
            'token' => 'falso',
            'email' => $user->email,
            'password' => 'NuovissimaPassword1!',
            'password_confirmation' => 'NuovissimaPassword1!',
        ])->assertSessionHasErrors(['email' => 'Il link per reimpostare la password non è valido o è scaduto.']);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_unused_two_factor_routes_are_disabled(): void
    {
        $this->get('/two-factor-challenge')->assertNotFound();
    }

    public function test_new_users_must_verify_their_email_before_selling_or_buying(): void
    {
        Notification::fake();

        $this->post('/register', [
            'name' => 'Mario Rossi',
            'email' => 'mario@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $user = User::where('email', 'mario@example.com')->first();
        Notification::assertSentTo($user, QueuedVerifyEmail::class);

        $this->get(route('create.article'))->assertRedirect(route('verification.notice'));
        $this->get(route('verification.notice'))->assertOk()->assertSee(__('ui.verify_email_title'));
        $this->post(route('become.revisor'))->assertRedirect(route('verification.notice'));

        $article = Article::factory()->accepted()->create();
        $this->post(route('cart.add', $article))->assertRedirect(route('verification.notice'));

        Livewire::actingAs($user)->test(CreateArticleForm::class)->call('store')->assertForbidden();

        $link = URL::temporarySignedRoute('verification.verify', now()->addHour(), ['id' => $user->id, 'hash' => sha1($user->email)]);
        $this->get($link);

        $this->assertTrue($user->fresh()->hasVerifiedEmail());
        $this->get(route('create.article'))->assertOk();
    }

    public function test_changing_email_needs_the_password_and_a_new_verification(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'prima@example.com', 'password' => 'Password123!']);

        $this->actingAs($user)->put(route('user-profile-information.update'), ['name' => $user->name, 'email' => 'dopo@example.com'])
            ->assertSessionHasErrorsIn('updateProfileInformation', 'current_password');
        $this->actingAs($user)->put(route('user-profile-information.update'), ['name' => $user->name, 'email' => 'dopo@example.com', 'current_password' => 'sbagliata'])
            ->assertSessionHasErrorsIn('updateProfileInformation', 'current_password');
        $this->assertSame('prima@example.com', $user->fresh()->email);

        $this->actingAs($user)->put(route('user-profile-information.update'), ['name' => $user->name, 'email' => 'dopo@example.com', 'current_password' => 'Password123!'])
            ->assertSessionHasNoErrors();

        $this->assertSame('dopo@example.com', $user->fresh()->email);
        $this->assertFalse($user->fresh()->hasVerifiedEmail());
        Notification::assertSentTo($user, QueuedVerifyEmail::class);
    }

    public function test_changing_password_closes_other_sessions_but_not_this_one(): void
    {
        $user = User::factory()->create(['password' => 'Password123!']);

        $this->actingAs($user)->get(route('profile.show'))->assertOk();

        $this->put(route('user-password.update'), [
            'current_password' => 'Password123!',
            'password' => 'NuovaPassword456!',
            'password_confirmation' => 'NuovaPassword456!',
        ])->assertSessionHasNoErrors();

        // Questa sessione resta aperta
        $this->get(route('profile.show'))->assertOk();

        // Un altro dispositivo ha ancora l'impronta della vecchia password
        $this->withSession(['password_hash_web' => 'impronta-vecchia'])->get(route('profile.show'))->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_register_and_reset_forms_are_rate_limited_by_ip(): void
    {
        foreach (range(1, 10) as $n) {
            $this->post('/forgot-password', ['email' => "utente{$n}@example.com"])->assertRedirect();
        }

        $this->post('/forgot-password', ['email' => 'altro@example.com'])->assertStatus(429);
    }

    public function test_reset_form_does_not_reveal_which_emails_exist(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $known = $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email]);
        $unknown = $this->from('/forgot-password')->post('/forgot-password', ['email' => 'nessuno@example.com']);

        $unknown->assertRedirect('/forgot-password')->assertSessionHasNoErrors();
        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
    }

    public function test_a_mail_server_failure_does_not_become_an_error_page(): void
    {
        Queue::fake();
        // Porta chiusa: se un'email partisse durante la richiesta, la risposta sarebbe un errore 500
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => 1]);

        $this->post('/register', [
            'name' => 'Mario Rossi',
            'email' => 'mario@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertRedirect('/annunci/nuovo');

        $user = User::where('email', 'mario@example.com')->first();
        $this->post(route('verification.send'))->assertRedirect();
        $user->markEmailAsVerified();
        $this->actingAs($user)->post(route('become.revisor'))->assertRedirect(route('homepage'));
        auth()->logout();
        $this->from('/forgot-password')->post('/forgot-password', ['email' => $user->email])->assertRedirect('/forgot-password');

        Queue::assertPushed(SendQueuedNotifications::class, 3);
        Queue::assertPushed(SendQueuedMailable::class, 1);
    }

    public function test_queued_emails_are_paced_and_limited_by_errors_not_by_attempts(): void
    {
        $job = new SendQueuedNotifications(collect([User::factory()->create()]), new QueuedVerifyEmail, ['mail']);

        // Nessun numero di tentativi: le attese dovute al limite non devono consumarli
        $this->assertNull($job->tries);
        $this->assertSame(4, $job->maxExceptions);
        $this->assertTrue($job->retryUntil()->isFuture());
        $this->assertCount(3, $job->backoff());
    }

    public function test_every_kind_of_email_goes_through_the_sending_limit(): void
    {
        Queue::fake();
        config(['queue.default' => 'database']);
        $user = User::factory()->create();
        $limited = fn ($job) => collect($job->middleware)->contains(fn ($middleware) => $middleware instanceof RateLimited);

        $user->sendEmailVerificationNotification();
        $user->sendPasswordResetNotification('token');
        $this->actingAs($user)->post(route('become.revisor'));

        Queue::assertPushed(SendQueuedNotifications::class, 2);
        Queue::assertPushed(SendQueuedNotifications::class, $limited);
        Queue::assertNotPushed(SendQueuedNotifications::class, fn ($job) => ! $limited($job));
        Queue::assertPushed(SendQueuedMailable::class, $limited);
    }

    public function test_sending_limit_lets_one_email_through_per_interval(): void
    {
        config(['mail.seconds_between_sends' => 10]);

        $limit = RateLimiter::limiter('mail-delivery')();

        $this->assertSame(1, $limit->maxAttempts);
        $this->assertSame(10, $limit->decaySeconds);
    }
}
