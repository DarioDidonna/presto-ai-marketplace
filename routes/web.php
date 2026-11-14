<?php

use App\Http\Controllers\ArticleController;
use App\Http\Controllers\CartController;
use App\Http\Controllers\ConversationController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\RevisorController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SellerController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Indirizzi in italiano, come il sito; raccolte al plurale (/annunci, /categorie, /venditori).
 * I permessi si leggono qui: auth e verified sulle rotte, le policy con "can:".
 * Login, registrazione e reset della password sono le rotte standard di Fortify.
 */

// Gli ID sono numeri: /annunci/nuovo e /carrello/invia non vengono mai scambiati per un annuncio
Route::pattern('article', '[0-9]+');
Route::pattern('category', '[0-9]+');
Route::pattern('user', '[0-9]+');
Route::pattern('purchaseRequest', '[0-9]+');

Route::get('/', [PublicController::class, 'homepage'])->name('homepage');
Route::post('/lingua/{lang}', [PublicController::class, 'setLanguage'])->whereIn('lang', config('app.available_locales'))->name('setLocale');

// ANNUNCI
Route::get('/annunci', [ArticleController::class, 'index'])->name('article.index');
Route::get('/annunci/nuovo', [ArticleController::class, 'create'])->middleware(['auth', 'verified'])->name('create.article');
Route::get('/annunci/{article}', [ArticleController::class, 'show'])->name('article.show');
// Solo l'autore (ArticlePolicy)
Route::get('/annunci/{article}/modifica', [ArticleController::class, 'edit'])->middleware(['auth', 'can:update,article'])->name('article.edit');
Route::delete('/annunci/{article}', [ArticleController::class, 'destroy'])->middleware(['auth', 'can:delete,article'])->name('article.destroy');

Route::get('/categorie/{category}', [ArticleController::class, 'byCategory'])->name('byCategory');
Route::get('/venditori/{user}', [SellerController::class, 'show'])->name('sellers.show');

// REVISORI
Route::middleware('isRevisor')->prefix('revisore')->group(function () {
    Route::get('/', [RevisorController::class, 'index'])->name('revisor.index');
    Route::patch('/annunci/{article}/accetta', [RevisorController::class, 'accept'])->middleware('can:review,article')->name('accept');
    Route::patch('/annunci/{article}/rifiuta', [RevisorController::class, 'reject'])->middleware('can:review,article')->name('reject');
    // Annulla l'ultima decisione (entro 5 minuti)
    Route::patch('/annulla', [RevisorController::class, 'undo'])->name('revisor.undo');
});

// Candidatura (utente) e conferma con il link firmato inviato all'amministratore
Route::post('/revisore/candidatura', [RevisorController::class, 'becomeRevisor'])->middleware(['auth', 'verified', 'throttle:revisor-request'])->name('become.revisor');
Route::get('/revisore/conferma/{user}', [RevisorController::class, 'confirmRevisor'])->middleware('signed:relative')->name('make.revisor');
Route::post('/revisore/conferma/{user}', [RevisorController::class, 'makeRevisor'])->middleware(['signed:relative', 'throttle:revisor-confirm'])->name('make.revisor.store');

// PROFILO
Route::middleware('auth')->prefix('profilo')->name('profile.')->group(function () {
    Route::get('/', [ProfileController::class, 'show'])->name('show');
    Route::post('/avatar', [ProfileController::class, 'updateAvatar'])->middleware('throttle:avatar')->name('avatar.update');
    Route::delete('/avatar', [ProfileController::class, 'destroyAvatar'])->name('avatar.destroy');
    Route::get('/dati', [ProfileController::class, 'export'])->middleware('throttle:profile-export')->name('export');
    Route::delete('/', [ProfileController::class, 'destroy'])->middleware('throttle:profile-destroy')->name('destroy');
});

// CARRELLO (solo utenti con l'email verificata)
Route::middleware(['auth', 'verified'])->prefix('carrello')->name('cart.')->group(function () {
    Route::get('/', [CartController::class, 'index'])->name('index');
    Route::delete('/', [CartController::class, 'clear'])->name('clear');
    Route::post('/invia', [PurchaseRequestController::class, 'store'])->middleware('throttle:checkout')->name('checkout');
    Route::post('/{article}', [CartController::class, 'add'])->middleware('can:addToCart,article')->name('add');
    Route::delete('/{article}', [CartController::class, 'remove'])->name('remove');
});

Route::middleware('auth')->group(function () {
    // ORDINI (richieste d'acquisto inviate)
    Route::get('/ordini', [PurchaseRequestController::class, 'index'])->name('orders.index');
    Route::patch('/ordini/{purchaseRequest}/annulla', [PurchaseRequestController::class, 'cancel'])->middleware('can:cancel,purchaseRequest')->name('orders.cancel');
    Route::post('/ordini/{purchaseRequest}/recensione', [ReviewController::class, 'store'])
        ->middleware(['can:review,purchaseRequest', 'throttle:review'])->name('reviews.store');

    // MESSAGGI tra acquirente e venditore di una richiesta d'acquisto
    Route::get('/richieste/{purchaseRequest}/messaggi', [ConversationController::class, 'show'])->middleware('can:view,purchaseRequest')->name('conversations.show');

    // VENDITE (richieste d'acquisto ricevute)
    Route::get('/vendite', [SalesController::class, 'index'])->name('sales.index');
    Route::patch('/vendite/{purchaseRequest}/accetta', [SalesController::class, 'accept'])->middleware('can:respond,purchaseRequest')->name('sales.accept');
    Route::patch('/vendite/{purchaseRequest}/rifiuta', [SalesController::class, 'decline'])->middleware('can:respond,purchaseRequest')->name('sales.decline');
});

/*
 * Vecchi indirizzi (prima del riordino): redirect permanente al nuovo, con gli stessi parametri e la stessa query
 * string (filtri, pagina). Solo le pagine GET: moduli e pulsanti usano già i nomi delle rotte.
 */
$movedTo = fn (string $route) => fn (Request $request) => redirect()->route(
    $route,
    [...$request->route()->parameters(), ...$request->query()],
    301,
);

Route::get('/article/index', $movedTo('article.index'));
Route::get('/search/article', $movedTo('article.index'));
Route::get('/create/article', $movedTo('create.article'));
Route::get('/show/article/{article}', $movedTo('article.show'));
Route::get('/article/{article}/edit', $movedTo('article.edit'));
Route::get('/category/{category}', $movedTo('byCategory'));
Route::get('/venditore/{user}', $movedTo('sellers.show'));
Route::get('/revisor/index', $movedTo('revisor.index'));
