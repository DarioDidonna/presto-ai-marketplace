<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class FrontendTest extends TestCase
{
    use RefreshDatabase;

    public function test_search_pagination_keeps_the_query(): void
    {
        Article::factory()->count(13)->accepted()->create(['title' => 'Chitarra elettrica']);

        $response = $this->get('/annunci?query=Chitarra&sort=price_asc')->assertOk();

        $response->assertSee('query=Chitarra&amp;sort=price_asc&amp;page=2', false);
        $this->get('/annunci?query=Chitarra&sort=price_asc&page=2')->assertOk()->assertViewHas('articles', fn ($a) => $a->count() === 1);
    }

    public function test_password_toggle_buttons_use_data_attribute(): void
    {
        $this->get('/login')->assertSee('data-toggle-password="password"', false)->assertDontSee('onclick=', false);
        $this->get('/register')
            ->assertSee('data-toggle-password="password"', false)
            ->assertSee('data-toggle-password="password_confirmation"', false)
            ->assertDontSee('onclick=', false);
    }

    public function test_footer_logo_is_complete_and_revisor_link_only_for_revisors(): void
    {
        $guestPage = $this->get('/')->assertOk();
        $this->assertSame(2, substr_count($guestPage->getContent(), 'PRESTO<span class="text-neon-cyan">.</span>IT'));
        $guestPage->assertDontSee(__('ui.revisor_area'));

        $this->actingAs(User::factory()->create())->get('/')->assertDontSee(__('ui.revisor_area'));
        $this->actingAs(User::factory()->revisor()->create())->get('/')->assertSee(__('ui.revisor_area'));
    }

    public function test_no_dead_links_except_dropdown_toggles(): void
    {
        $user = User::factory()->revisor()->create();
        $article = Article::factory()->accepted()->create();

        foreach (['/', '/annunci', "/annunci/{$article->id}", '/login', '/register'] as $url) {
            $html = $this->actingAs($user)->get($url)->getContent();
            preg_match_all('/<a\b[^>]*href="#"[^>]*>/', $html, $links);
            foreach ($links[0] as $link) {
                $this->assertStringContainsString('data-bs-toggle="dropdown"', $link, "Link morto in $url: $link");
            }
        }
    }

    public function test_pages_have_balanced_html_tags(): void
    {
        $article = Article::factory()->accepted()->create(['description' => "Riga uno\nRiga due"]);

        foreach (['/', "/annunci/{$article->id}", '/login'] as $url) {
            $html = $this->get($url)->getContent();
            foreach (['div', 'span', 'a', 'ul', 'li', 'section', 'footer', 'nav', 'form'] as $tag) {
                $this->assertSame(
                    preg_match_all("/<{$tag}[\\s>]/", $html),
                    substr_count($html, "</{$tag}>"),
                    "Tag <{$tag}> non bilanciato in {$url}"
                );
            }
        }
    }

    public function test_homepage_shows_real_statistics(): void
    {
        Article::factory()->count(3)->accepted()->create();
        Article::factory()->accepted()->create(['created_at' => now()->subMonth()]);
        Article::factory()->count(2)->create();
        Article::factory()->rejected()->create();

        $response = $this->get('/')->assertOk();

        $response->assertSee('id="headerCounterAnnouncements" data-count="4"', false);
        $response->assertSee('+3', false);
        $response->assertViewHas('articles');
        $this->assertStringNotContainsString('1420', $response->getContent());
        $this->assertStringNotContainsString('+340', $response->getContent());
    }

    public function test_radar_nodes_link_to_latest_published_articles(): void
    {
        $latest = Article::factory()->accepted()->create(['title' => 'Bici da corsa']);
        Article::factory()->create(['title' => 'Annuncio in revisione']);

        $this->get('/')
            ->assertSee(route('article.show', $latest), false)
            ->assertSee('Bici da corsa', false)
            ->assertDontSee('Annuncio in revisione', false);
    }

    public function test_category_counter_counts_only_published_articles(): void
    {
        $category = Category::factory()->create();
        Article::factory()->count(2)->accepted()->for($category)->create();
        Article::factory()->for($category)->create();
        Article::factory()->rejected()->for($category)->create();

        $this->get('/')->assertSee('2 '.__('ui.ads'));
    }

    public function test_flash_message_has_generic_title(): void
    {
        // Il titolo dell'avviso è generico (prima era un "Richiesta…" scritto a mano, sbagliato per altri messaggi)
        $html = $this->withSession(['message' => 'Operazione riuscita'])->get('/')
            ->assertSee('Operazione riuscita')
            ->getContent();
        $this->assertMatchesRegularExpression('#<strong[^>]*>\s*'.preg_quote(__('ui.notice'), '#').'\s*</strong>#', $html);
    }

    public function test_no_external_placeholder_images(): void
    {
        $article = Article::factory()->accepted()->create();

        foreach (['/', '/annunci', "/annunci/{$article->id}"] as $url) {
            $this->get($url)->assertDontSee('picsum.photos', false)->assertSee('media/placeholder.svg', false);
        }
    }

    public function test_contact_seller_button_depends_on_viewer(): void
    {
        $article = Article::factory()->accepted()->create();

        $this->get(route('article.show', $article))
            ->assertSee(__('ui.login_to_contact'))
            ->assertDontSee('mailto:', false);

        // L'email del venditore si vede solo dopo una vendita accettata, mai sulla pagina pubblica dell'annuncio
        $this->actingAs(User::factory()->create())->get(route('article.show', $article))
            ->assertDontSee($article->user->email)
            ->assertDontSee('mailto:', false);

        $this->actingAs($article->user)->get(route('article.show', $article))
            ->assertSee(__('ui.your_own_ad'))
            ->assertDontSee('mailto:', false);
    }

    public function test_author_sees_review_status_notice(): void
    {
        $pending = Article::factory()->create();
        $rejected = Article::factory()->rejected()->create();
        $accepted = Article::factory()->accepted()->create();

        $this->actingAs($pending->user)->get(route('article.show', $pending))->assertSee(__('ui.article_pending_notice'));
        $this->actingAs($rejected->user)->get(route('article.show', $rejected))->assertSee(__('ui.article_rejected_notice'));
        $this->actingAs($accepted->user)->get(route('article.show', $accepted))
            ->assertDontSee(__('ui.article_pending_notice'))
            ->assertDontSee(__('ui.article_rejected_notice'));
    }

    public function test_footer_has_no_fake_newsletter_or_version(): void
    {
        $this->get('/')
            ->assertDontSee('newsletterForm', false)
            ->assertDontSee('Core v10.4', false)
            ->assertSee(__('ui.footer_status_title'));
    }

    public function test_search_results_page_uses_dark_theme_and_shared_grid(): void
    {
        Article::factory()->accepted()->create(['title' => 'Lampada vintage']);

        $this->get('/annunci?query=Lampada')
            ->assertOk()
            ->assertDontSee('bg-light', false)
            ->assertDontSee('bg-white', false)
            ->assertSee('col-12 col-md-6 col-lg-4 d-flex', false);
    }

    public function test_navbar_highlights_current_section(): void
    {
        $this->get('/annunci')->assertSee('aria-current="page"', false);
        $html = $this->get('/annunci')->getContent();
        $this->assertMatchesRegularExpression('/nav-link-custom[^"]*\bactive\b[^"]*"\s+href="[^"]*\/annunci"/', $html);
    }

    public function test_display_url_falls_back_when_large_version_is_missing(): void
    {
        Storage::fake('public');
        $image = new Image(['path' => 'articles/1/foto.jpg']);

        // Nessun file sul disco: segnaposto invece di un'immagine rotta
        $this->assertStringEndsWith('media/placeholder.svg', $image->getDisplayUrl());

        Storage::disk('public')->put('articles/1/foto.jpg', 'x');
        $this->assertStringEndsWith('articles/1/foto.jpg', $image->getDisplayUrl());

        Storage::disk('public')->put('articles/1/crop_300x300_foto.jpg', 'x');
        $this->assertStringEndsWith('crop_300x300_foto.jpg', $image->getDisplayUrl());

        Storage::disk('public')->put('articles/1/crop_800x800_foto.jpg', 'x');
        $this->assertStringEndsWith('crop_800x800_foto.jpg', $image->getDisplayUrl());
    }

    public function test_thumbnail_falls_back_to_original_then_placeholder(): void
    {
        Storage::fake('public');
        $image = new Image(['path' => 'articles/1/foto.jpg']);

        $this->assertStringEndsWith('media/placeholder.svg', $image->thumbnailUrl());

        Storage::disk('public')->put('articles/1/foto.jpg', 'x'); // caricata, miniatura non ancora generata
        $this->assertStringEndsWith('articles/1/foto.jpg', $image->thumbnailUrl());

        Storage::disk('public')->put('articles/1/crop_300x300_foto.jpg', 'x');
        $this->assertStringEndsWith('crop_300x300_foto.jpg', $image->thumbnailUrl());
    }

    public function test_every_css_class_used_in_views_is_defined(): void
    {
        // Classi che servono solo come aggancio per JS/Bootstrap, senza stile proprio
        $hooks = ['class-step', 'slide', 'node-active', 'node-pos-', 'preview-container', 'brand-icon', 'brand-icon-wrapper',
            'profile-dropdown-toggle', 'custom-checkbox-wrapper', 'stat-number', 'article-show-wrapper', 'transform-wrapper'];

        $css = file_get_contents(resource_path('css/style.css')).file_get_contents(base_path('node_modules/bootstrap/dist/css/bootstrap.css'));
        preg_match_all('/\.((?:[a-zA-Z][\w-]*)(?:\\\\\.\w+)?)/', $css, $m);
        $defined = array_flip(array_map(fn ($c) => str_replace('\\.', '.', $c), $m[1]));

        $missing = [];
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'))) as $file) {
            if (! str_ends_with($file, '.blade.php')) {
                continue;
            }
            preg_match_all('/class="([^"]*)"/', file_get_contents($file), $attrs);
            foreach ($attrs[1] as $attr) {
                $attr = preg_replace('/\{\{.*?\}\}|@\w+\([^)]*\)/', '', $attr);
                foreach (preg_split('/\s+/', trim($attr)) as $class) {
                    if ($class === '' || ! preg_match('/^[a-zA-Z][\w.-]*$/', $class)) {
                        continue;
                    }
                    if (str_starts_with($class, 'bi') || str_starts_with($class, 'fa') || in_array($class, $hooks, true)) {
                        continue;
                    }
                    if (! isset($defined[$class])) {
                        $missing[$class][] = basename($file);
                    }
                }
            }
        }

        $this->assertSame([], array_map('array_unique', $missing), 'Classi CSS usate ma non definite');
    }

    public function test_each_page_has_its_own_title_and_description(): void
    {
        $article = Article::factory()->accepted()->create(['title' => 'Divano letto', 'description' => 'Comodo divano in tessuto grigio']);

        $this->get('/')->assertSee('<title>Presto.it - '.__('ui.meta_tagline').'</title>', false);
        $this->get('/annunci')->assertSee('<title>'.__('ui.all_ads_title').' · Presto.it</title>', false);
        $this->get(route('article.show', $article))
            ->assertSee('<title>Divano letto · Presto.it</title>', false)
            ->assertSee('<meta name="description" content="Comodo divano in tessuto grigio">', false);
        $this->get('/login')->assertSee('<title>'.__('ui.login').' · Presto.it</title>', false);
    }

    public function test_english_pages_contain_no_leftover_italian_labels(): void
    {
        $article = Article::factory()->accepted()->create();
        $this->withSession(['locale' => 'en']);

        foreach (['/', "/annunci/{$article->id}"] as $url) {
            $this->get($url)->assertOk()
                ->assertDontSee('Esplora')
                ->assertDontSee('Elaborazione')
                ->assertDontSee('La piattaforma di annunci')
                ->assertDontSee('AI Text Moderation');
        }
    }

    public function test_avatar_is_generated_locally(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Mario Rossi']))->get('/')
            ->assertDontSee('ui-avatars.com', false)
            ->assertSee('MR');
    }

    public function test_revisor_photo_list_has_readable_text(): void
    {
        $article = Article::factory()->create();
        $article->images()->create(['path' => 'articles/1/a.jpg'])->forceFill(['faces_count' => 1])->save();

        $this->actingAs(User::factory()->revisor()->create())->get(route('revisor.index'))
            ->assertSee(__('ui.image_number', ['n' => 1]))
            ->assertSee(trans_choice('ui.faces_hidden', 1, ['count' => 1]))
            ->assertDontSee('Imm #', false);
    }

    public function test_card_images_are_lazy_loaded(): void
    {
        Article::factory()->accepted()->create();
        $this->get('/annunci')->assertSee('loading="lazy"', false);
    }

    public function test_site_styles_are_loaded_after_bootstrap_and_font_awesome_is_gone(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertLessThan(strpos($css, "@import './style.css'"), strpos($css, "@import 'bootstrap/dist/css/bootstrap.min.css'"));
        $this->assertStringNotContainsString('font-awesome', $css);
        preg_match('/input:\s*\[([^\]]*)\]/', file_get_contents(base_path('vite.config.js')), $input);
        $this->assertStringNotContainsString('style.css', $input[1]);
        $this->assertStringNotContainsString("'fa fa-", file_get_contents(database_path('seeders/CategoriesSeeder.php')));
    }
}
