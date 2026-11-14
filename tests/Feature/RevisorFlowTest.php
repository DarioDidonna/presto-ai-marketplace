<?php

namespace Tests\Feature;

use App\Jobs\RemoveFaces;
use App\Mail\BecomeRevisor;
use App\Models\Article;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RevisorFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_revisor_sees_oldest_pending_article_first(): void
    {
        $oldest = Article::factory()->create(['created_at' => now()->subDays(2), 'title' => 'Il più vecchio']);
        Article::factory()->create(['created_at' => now()->subDay(), 'title' => 'Il più recente']);
        Article::factory()->accepted()->create(['created_at' => now()->subDays(5), 'title' => 'Già approvato']);

        $response = $this->actingAs(User::factory()->revisor()->create())->get(route('revisor.index'));

        $response->assertOk()->assertSee($oldest->title)->assertDontSee('Il più recente');
        $this->assertTrue($response->viewData('article_to_check')->is($oldest));
    }

    public function test_revisor_with_empty_queue_sees_completion_message(): void
    {
        $this->actingAs(User::factory()->revisor()->create())
            ->get(route('revisor.index'))
            ->assertOk()
            ->assertSee(__('ui.no_new_articles'));
    }

    public function test_accepting_publishes_article_and_rejecting_hides_it(): void
    {
        $revisor = User::factory()->revisor()->create();
        $good = Article::factory()->create();
        $bad = Article::factory()->create();

        $this->actingAs($revisor)->from(route('revisor.index'))->patch(route('accept', $good))
            ->assertRedirect(route('revisor.index'))
            ->assertSessionHas('message');
        $this->actingAs($revisor)->from(route('revisor.index'))->patch(route('reject', $bad));

        $this->assertTrue($good->fresh()->is_accepted);
        $this->assertFalse($bad->fresh()->is_accepted);

        $this->get(route('article.index'))->assertSee($good->title)->assertDontSee($bad->title);
        $this->get(route('homepage'))->assertSee($good->title)->assertDontSee($bad->title);
    }

    public function test_revisor_request_mail_contains_working_signed_link(): void
    {
        Mail::fake();
        config(['app.admin_email' => 'responsabile@example.com']);
        $candidate = User::factory()->create();

        $this->actingAs($candidate)->post(route('become.revisor'));

        Mail::assertQueued(BecomeRevisor::class, function (BecomeRevisor $mail) use ($candidate) {
            $html = $mail->render();
            preg_match('#href="([^"]*/revisore/conferma/[^"]+)"#', $html, $match);
            $this->assertNotEmpty($match, 'Link di attivazione non trovato nella mail');

            auth()->logout();
            $link = html_entity_decode($match[1]);
            $this->get($link)->assertOk();
            $this->assertFalse((bool) $candidate->fresh()->is_revisor);
            $this->post($link)->assertRedirect(route('homepage'));

            return $mail->hasTo('responsabile@example.com') && $candidate->fresh()->is_revisor;
        });
    }

    public function test_make_user_revisor_command(): void
    {
        $user = User::factory()->create();

        $this->artisan('app:make-user-revisor', ['email' => $user->email])->assertSuccessful();
        $this->assertTrue((bool) $user->fresh()->is_revisor);

        $this->artisan('app:make-user-revisor', ['email' => 'nessuno@example.com'])
            ->expectsOutput('Utente non trovato')
            ->assertFailed();
    }

    public function test_analyze_images_command_queues_only_missing_analyses(): void
    {
        Queue::fake();
        $article = Article::factory()->create();
        $article->images()->create(['path' => 'articles/1/senza.jpg']);
        $article->images()->create(['path' => 'articles/1/con.jpg'])->forceFill(['faces_count' => 0])->save();
        // Volti non controllati (servizio locale spento al caricamento): da rifare
        $article->images()->create(['path' => 'articles/1/volti.jpg']);

        $this->artisan('app:analyze-images')->assertSuccessful();
        Queue::assertPushed(RemoveFaces::class, 2);

        $this->artisan('app:analyze-images', ['--all' => true])->assertSuccessful();
        Queue::assertPushed(RemoveFaces::class, 5); // 2 di prima + tutte e 3
    }

    public function test_accept_and_reject_require_confirmation_in_a_modal(): void
    {
        $article = Article::factory()->create(['title' => 'Tavolo in legno']);

        $html = $this->actingAs(User::factory()->revisor()->create())
            ->get(route('revisor.index'))
            ->assertOk()
            ->assertSee(__('ui.confirm_accept_title'))
            ->assertSee(__('ui.confirm_reject_title'))
            ->assertSee(__('ui.confirm_accept_text', ['title' => 'Tavolo in legno']))
            ->getContent();

        // I pulsanti visibili aprono solo la modale: nessun submit diretto
        $this->assertStringContainsString('data-bs-target="#confirmAcceptModal"', $html);
        $this->assertStringContainsString('data-bs-target="#confirmRejectModal"', $html);

        // Ogni form PATCH sta dentro la rispettiva modale
        foreach (['confirmAcceptModal' => 'accept', 'confirmRejectModal' => 'reject'] as $modal => $route) {
            $start = strpos($html, 'id="'.$modal.'"');
            $form = strpos($html, 'action="'.route($route, $article).'"');
            $this->assertNotFalse($start);
            $this->assertGreaterThan($start, $form, "Il form $route deve trovarsi dentro $modal");
        }
        $this->assertSame(2, substr_count($html, 'name="_method" value="PATCH"'));
    }
}
