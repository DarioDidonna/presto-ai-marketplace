<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\PurchaseRequest;
use App\Models\User;
use App\Services\Checkout;
use App\Services\CheckoutException;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Due acquirenti che inviano il carrello nello stesso istante: il lock sulle righe (lockForUpdate) deve impedire che
 * entrambi vedano l'annuncio libero. Serve un database vero con due connessioni: gira solo su MySQL (in CI), perché
 * SQLite non ha lock sulle righe e il test lì non dimostrerebbe nulla.
 */
class CheckoutConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    // Niente transazione attorno al test: la seconda connessione deve vedere i dati, quindi vanno confermati
    protected array $connectionsToTransact = [];

    private Connection $rival;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Serve MySQL: SQLite non ha lock sulle righe.');
        }

        Notification::fake();
        // Seconda connessione allo stesso database: l'altro acquirente
        config(['database.connections.rival' => config('database.connections.'.DB::getDefaultConnection())]);
        $this->rival = DB::connection('rival');
        // Un'attesa del lock oltre 1 secondo diventa un errore invece di bloccare il test
        DB::statement('SET SESSION innodb_lock_wait_timeout = 1');
    }

    protected function tearDown(): void
    {
        if (isset($this->rival)) {
            if ($this->rival->transactionLevel() > 0) {
                $this->rival->rollBack();
            }
            $this->rival->disconnect();

            // I dati sono stati confermati: si svuotano le tabelle per non lasciarli ai test successivi
            DB::statement('SET FOREIGN_KEY_CHECKS = 0');
            foreach (DB::getSchemaBuilder()->getTableListing(schemaQualified: false) as $table) {
                if ($table !== 'migrations') {
                    DB::table($table)->truncate();
                }
            }
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }

        parent::tearDown();
    }

    public function test_a_checkout_waits_for_the_one_already_reserving_the_article_and_then_sees_it_taken(): void
    {
        $buyer = User::factory()->create();
        $article = Article::factory()->accepted()->create(['title' => 'Bici da corsa']);
        $buyer->cart()->attach($article);

        // L'altro acquirente sta inviando il suo carrello: ha bloccato la riga e la sta riservando, senza aver confermato
        $this->rival->beginTransaction();
        $this->rival->table('articles')->where('id', $article->id)->lockForUpdate()->first();
        $this->rival->table('articles')->where('id', $article->id)->update(['reserved_at' => now()]);

        // Il checkout aspetta già sulla lettura (FOR UPDATE): senza lock leggerebbe l'annuncio ancora libero
        try {
            app(Checkout::class)->submit($buyer, null);
            $this->fail('Il checkout non ha aspettato il lock dell\'altro acquirente.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('Lock wait timeout', $error->getMessage());
            $this->assertStringContainsStringIgnoringCase('for update', $error->getSql());
        }
        $this->assertSame(0, PurchaseRequest::count());

        // L'altro conferma: ora il checkout legge il dato aggiornato e non riserva l'annuncio una seconda volta
        $this->rival->commit();

        try {
            app(Checkout::class)->submit($buyer, null);
            $this->fail('L\'annuncio già riservato è stato richiesto una seconda volta.');
        } catch (CheckoutException $error) {
            $this->assertStringContainsString('Bici da corsa', $error->getMessage());
        }
        $this->assertSame(0, PurchaseRequest::count());
        Notification::assertNothingSent();
    }
}
