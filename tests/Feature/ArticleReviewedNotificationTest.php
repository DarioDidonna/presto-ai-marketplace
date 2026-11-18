<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\User;
use App\Notifications\ArticleReviewed;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ArticleReviewedNotificationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    public function test_author_is_notified_when_article_is_accepted_or_rejected(): void
    {
        $revisor = User::factory()->revisor()->create();
        $accepted = Article::factory()->create();
        $rejected = Article::factory()->create();

        $this->actingAs($revisor)->patch(route('revisor.accept', $accepted));
        $this->actingAs($revisor)->patch(route('revisor.reject', $rejected));

        Notification::assertSentTo($accepted->user, ArticleReviewed::class, fn ($n) => $n->accepted === true && $n->article->is($accepted));
        Notification::assertSentTo($rejected->user, ArticleReviewed::class, fn ($n) => $n->accepted === false && $n->article->is($rejected));
        Notification::assertNotSentTo($revisor, ArticleReviewed::class);
    }

    public function test_no_duplicate_email_when_status_does_not_change(): void
    {
        $revisor = User::factory()->revisor()->create();
        $article = Article::factory()->accepted()->create();

        $this->actingAs($revisor)->patch(route('revisor.accept', $article));

        Notification::assertNothingSent();
    }

    public function test_notification_is_queued_and_sent_after_commit(): void
    {
        $notification = new ArticleReviewed(Article::factory()->create(), true);

        $this->assertInstanceOf(ShouldQueue::class, $notification);
        $this->assertTrue($notification->afterCommit);
    }

    public function test_email_content_for_accepted_and_rejected_articles(): void
    {
        $author = User::factory()->create(['name' => 'Giulia']);
        $article = Article::factory()->for($author)->create(['title' => 'Bici da corsa']);

        // Come nell'invio reale: rendering nella lingua dell'autore (italiano di default)
        app()->setLocale($author->preferredLocale());
        $render = fn (bool $ok) => (new ArticleReviewed($article, $ok))->toMail($author);

        $accepted = $render(true);
        $this->assertSame('Il tuo annuncio «Bici da corsa» è stato pubblicato', $accepted->subject);
        $this->assertSame('Ciao Giulia,', $accepted->greeting);
        $this->assertSame(route('articles.show', $article), $accepted->actionUrl);

        $rejected = $render(false);
        $this->assertSame('Il tuo annuncio «Bici da corsa» non è stato approvato', $rejected->subject);
        $this->assertSame(route('articles.edit', $article), $rejected->actionUrl);
    }

    public function test_email_uses_the_author_language_not_the_revisor_one(): void
    {
        $author = User::factory()->create(['locale' => 'en']);
        $article = Article::factory()->for($author)->create(['title' => 'Road bike']);

        $this->actingAs(User::factory()->revisor()->create(['locale' => 'it']))
            ->withSession(['locale' => 'it'])
            ->patch(route('revisor.accept', $article));

        Notification::assertSentTo($author, ArticleReviewed::class, function ($notification, $channels, $notifiable, $locale) {
            return $locale === 'en';
        });

        // Il rendering avviene nella lingua dell'autore
        app()->setLocale($author->preferredLocale());
        $mail = (new ArticleReviewed($article, true))->toMail($author);
        $this->assertSame('Your ad “Road bike” has been published', $mail->subject);
    }

    public function test_language_choice_is_saved_on_user_and_restored_at_login(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/lingua/es');
        $this->assertSame('es', $user->fresh()->locale);

        $this->post('/logout');
        $this->flushSession();
        $this->post('/login', ['email' => $user->email, 'password' => 'password']);

        $this->assertSame('es', session('locale'));
        $this->assertSame('es', $user->fresh()->preferredLocale());
    }

    public function test_new_users_get_the_language_used_at_registration(): void
    {
        $this->withSession(['locale' => 'en'])->post('/register', [
            'name' => 'John',
            'email' => 'john@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ]);

        $this->assertSame('en', User::where('email', 'john@example.com')->value('locale'));
    }
}
