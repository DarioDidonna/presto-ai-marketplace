<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\Category;
use App\Models\Image;
use App\Models\Message;
use App\Models\PurchaseRequest;
use App\Models\Review;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class DemoSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_demo_seeder_creates_users_articles_and_images(): void
    {
        $this->seed(DemoSeeder::class);

        $this->assertSame(10, Category::count());
        $this->assertSame(5, User::count());
        $this->assertTrue((bool) User::where('email', 'revisore@presto.it')->value('is_revisor'));
        $this->assertFalse((bool) User::where('email', 'demo@presto.it')->value('is_revisor'));

        $this->assertSame(24, Article::count());
        $this->assertGreaterThan(0, Article::whereNull('is_accepted')->count());
        $this->assertGreaterThan(0, Article::where('is_accepted', false)->count());
        $this->assertGreaterThan(15, Article::where('is_accepted', true)->count());
        $this->assertGreaterThan(0, Article::where('created_at', '>=', now()->subWeek())->count());

        // Ogni immagine ha l'originale e le due versioni con watermark
        $this->assertSame(24, Image::count());
        foreach (Image::all() as $image) {
            $dir = dirname($image->path);
            $name = basename($image->path);
            Storage::disk('public')->assertExists([$image->path, "$dir/crop_300x300_$name", "$dir/crop_800x800_$name"]);
        }
        // Ogni annuncio ha la sua foto vera (database/seeders/demo-images/{slug}.jpg), non una a caso della categoria
        $bike = Article::where('title', 'Bicicletta da corsa in alluminio')->first();
        $this->assertCount(1, $bike->images);
        $this->assertFileEquals(
            database_path('seeders/demo-images/bicicletta-da-corsa-in-alluminio.jpg'),
            Storage::disk('public')->path($bike->images->first()->path),
        );
        foreach (Article::all() as $article) {
            $this->assertFileExists(database_path('seeders/demo-images/'.Str::slug($article->title).'.jpg'), 'foto mancante per '.$article->title);
        }

        // Le pagine del sito popolato funzionano e la ricerca trova gli annunci demo
        $article = Article::accepted()->first();
        foreach (['/', '/annunci', "/categorie/{$article->category_id}", "/annunci/{$article->id}"] as $url) {
            $this->get($url)->assertOk();
        }
        $this->get('/annunci?query=bicicletta')->assertOk()->assertSee('Bicicletta da corsa');

        // Carrello e richieste d'acquisto in tutti gli stati, coerenti con gli annunci
        $demo = User::where('email', 'demo@presto.it')->first();
        $this->assertSame(2, $demo->cart()->count());
        $this->assertSame(0, $demo->cart()->where(fn ($q) => $q->whereNotNull('reserved_at')->orWhereNotNull('sold_at'))->count());
        $this->assertEqualsCanonicalizing(
            [PurchaseRequest::PENDING, PurchaseRequest::ACCEPTED, PurchaseRequest::DECLINED],
            $demo->purchaseRequests()->pluck('status')->all(),
        );
        $this->assertSame(1, $demo->receivedPurchaseRequests()->where('status', PurchaseRequest::PENDING)->count());
        foreach (PurchaseRequest::with('items.article')->get() as $request) {
            foreach ($request->items as $item) {
                $this->assertSame($request->seller_id, $item->article->user_id);
                $this->assertSame($request->status === PurchaseRequest::ACCEPTED, $item->article->isSold());
                $this->assertSame($request->status !== PurchaseRequest::DECLINED, $item->article->isReserved());
            }
        }

        // Recensioni demo: il demo ne ha ricevuta una e ha un acquisto ancora da recensire
        $this->assertSame(['average' => 5.0, 'count' => 1], $demo->reviewSummary());
        $this->assertTrue($demo->purchaseRequests()->where('status', PurchaseRequest::ACCEPTED)->get()->contains(fn ($request) => $demo->can('review', $request)));

        // Messaggi demo: uno non letto tra gli ordini e uno tra le vendite
        $this->assertSame(['orders' => 1, 'sales' => 1], $demo->unreadMessageCounts());

        $this->actingAs($demo);
        foreach ([route('cart.index'), route('orders.index'), route('sales.index'), route('sellers.show', $demo)] as $url) {
            $this->get($url)->assertOk();
        }
        auth()->logout();

        // Le credenziali demo funzionano
        $this->post('/login', ['email' => 'revisore@presto.it', 'password' => DemoSeeder::PASSWORD]);
        $this->assertAuthenticatedAs(User::where('email', 'revisore@presto.it')->first());
        $this->get(route('revisor.index'))->assertOk();
    }

    public function test_seeder_can_run_twice_without_duplicates(): void
    {
        $this->seed(DemoSeeder::class);
        $this->seed(DemoSeeder::class);

        $this->assertSame(10, Category::count());
        $this->assertSame(5, User::count());
        $this->assertSame(24, Article::count());
        $this->assertSame(6, PurchaseRequest::count());
        $this->assertSame(2, Review::count());
        $this->assertSame(6, Message::count());
    }
}
