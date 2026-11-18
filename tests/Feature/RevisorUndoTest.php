<?php

namespace Tests\Feature;

use App\Http\Controllers\RevisorController;
use App\Models\Article;
use App\Models\User;
use App\Notifications\ArticleReviewed;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class RevisorUndoTest extends TestCase
{
    use RefreshDatabase;

    private User $revisor;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        $this->revisor = User::factory()->revisor()->create();
    }

    public function test_revisor_can_undo_last_decision_and_article_returns_to_queue(): void
    {
        $article = Article::factory()->create(['title' => 'Tavolo antico']);

        $this->actingAs($this->revisor)->from(route('revisor.index'))->patch(route('revisor.accept', $article));
        $this->assertTrue($article->fresh()->is_accepted);

        // La dashboard mostra subito il pulsante "Annulla", anche con la coda vuota
        $this->actingAs($this->revisor)->get(route('revisor.index'))
            ->assertSee(__('ui.no_new_articles'))
            ->assertSee('action="'.route('revisor.undo').'"', false);

        $this->actingAs($this->revisor)->patch(route('revisor.undo'))
            ->assertRedirect(route('revisor.index'))
            ->assertSessionHas('message', __('ui.undo_done', ['title' => 'Tavolo antico']));

        $this->assertNull($article->fresh()->is_accepted);
        $this->actingAs($this->revisor)->get(route('revisor.index'))
            ->assertViewHas('article_to_check', fn ($a) => $a->is($article))
            ->assertDontSee('action="'.route('revisor.undo').'"', false);
    }

    public function test_undo_works_only_once(): void
    {
        $article = Article::factory()->create();
        $this->actingAs($this->revisor)->patch(route('revisor.reject', $article));

        $this->actingAs($this->revisor)->patch(route('revisor.undo'))->assertSessionHas('message');
        $this->actingAs($this->revisor)->patch(route('revisor.undo'))->assertSessionHas('errorMessage', __('ui.undo_expired'));
    }

    public function test_undo_is_refused_after_the_time_window(): void
    {
        $article = Article::factory()->create();
        $this->actingAs($this->revisor)->patch(route('revisor.accept', $article));

        $this->travel(RevisorController::UNDO_MINUTES + 1)->minutes();

        $this->actingAs($this->revisor)->patch(route('revisor.undo'))->assertSessionHas('errorMessage', __('ui.undo_expired'));
        $this->assertTrue($article->fresh()->is_accepted);
    }

    public function test_undo_is_refused_if_the_article_changed_meanwhile(): void
    {
        $article = Article::factory()->create();
        $this->actingAs($this->revisor)->patch(route('revisor.accept', $article));

        // Un altro revisore (con la sua sessione) cambia l'esito nel frattempo
        $this->travel(1)->minutes();
        $article->fresh()->setAccepted(false);

        $this->actingAs($this->revisor)->patch(route('revisor.undo'))->assertSessionHas('errorMessage');
        $this->assertFalse($article->fresh()->is_accepted);
    }

    public function test_only_revisors_can_undo(): void
    {
        $this->patch(route('revisor.undo'))->assertRedirect(route('homepage'));
        $this->actingAs(User::factory()->create())->patch(route('revisor.undo'))->assertRedirect(route('homepage'));
    }

    public function test_author_email_is_delayed_by_the_undo_window(): void
    {
        $this->freezeTime();
        $article = Article::factory()->create();
        $this->actingAs($this->revisor)->patch(route('revisor.accept', $article));

        Notification::assertSentTo($article->user, ArticleReviewed::class, function (ArticleReviewed $notification) {
            return $notification->delay->equalTo(now()->addMinutes(RevisorController::UNDO_MINUTES));
        });
    }

    public function test_email_is_not_sent_if_the_decision_was_undone_or_changed(): void
    {
        $article = Article::factory()->create();
        $this->actingAs($this->revisor)->patch(route('revisor.accept', $article));

        $sent = null;
        Notification::assertSentTo($article->user, ArticleReviewed::class, function ($n) use (&$sent) {
            $sent = $n;

            return true;
        });

        // Decisione ancora valida: l'email parte
        $this->assertTrue($sent->shouldSend($article->user, 'mail'));

        // Decisione annullata: l'email non parte
        $this->travel(1)->minutes();
        $this->actingAs($this->revisor)->patch(route('revisor.undo'));
        $this->assertFalse($sent->shouldSend($article->user, 'mail'));

        // Riaccettato più tardi: vale solo la nuova email, non quella vecchia
        $this->travel(1)->minutes();
        $this->actingAs($this->revisor)->patch(route('revisor.accept', $article));
        $this->assertFalse($sent->shouldSend($article->user, 'mail'));

        // Annuncio eliminato dall'autore
        $article->delete();
        $this->assertFalse($sent->shouldSend($article->user, 'mail'));
    }
}
