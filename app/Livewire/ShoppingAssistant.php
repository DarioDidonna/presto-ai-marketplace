<?php

namespace App\Livewire;

use App\Models\Article;
use App\Services\AgentUnavailableException;
use App\Services\AiAgentClient;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Chat con l'assistente all'acquisto (agente AI in ai-agent/), presente in tutte le pagine.
 */
class ShoppingAssistant extends Component
{
    public const SESSION_KEY = 'shopping_assistant';

    public const MAX_MESSAGES_PER_MINUTE = 10;

    // Messaggi conservati in sessione: la conversazione continua cambiando pagina
    private const MAX_STORED_MESSAGES = 30;

    public bool $open = false;

    public string $input = '';

    /** @var list<array{role: string, text: string, articles?: list<array<string, mixed>>}> */
    #[Locked]
    public array $messages = [];

    #[Locked]
    public ?string $conversationId = null;

    public function mount(): void
    {
        $stored = session(self::SESSION_KEY, []);
        $this->messages = $stored['messages'] ?? [];
        $this->conversationId = $stored['conversation_id'] ?? null;
        $this->open = $stored['open'] ?? false;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
        $this->persist();
    }

    public function send(AiAgentClient $agent): void
    {
        $this->input = trim($this->input);
        $this->validate(['input' => 'required|string|max:500'], [
            'input.required' => __('ui.assistant_empty'),
            'input.max' => __('ui.assistant_too_long'),
        ]);

        $limiterKey = 'shopping-assistant:'.(auth()->id() ?? request()->ip());
        if (RateLimiter::tooManyAttempts($limiterKey, self::MAX_MESSAGES_PER_MINUTE)) {
            $this->addError('input', __('ui.assistant_too_many', ['seconds' => RateLimiter::availableIn($limiterKey)]));

            return;
        }
        RateLimiter::hit($limiterKey);

        $message = $this->input;
        $this->messages[] = ['role' => 'user', 'text' => $message];
        $this->input = '';

        try {
            $result = $agent->chat($message, $this->conversationId, app()->getLocale());
            $this->conversationId = $result['conversation_id'];
            $this->messages[] = [
                'role' => 'assistant',
                'text' => $this->cleanReply($result['reply']),
                'articles' => $this->cards($result['article_ids']),
            ];
        } catch (AgentUnavailableException $error) {
            report($error);
            $this->messages[] = ['role' => 'error', 'text' => __('ui.assistant_unavailable')];
        }

        $this->persist();
    }

    // "Aggiungi al carrello" dalle schede della chat: stesse regole della pagina dell'annuncio
    public function addToCart(int $articleId): void
    {
        $user = auth()->user();
        $article = Article::find($articleId);

        if (! $user || ! $article || ! Gate::forUser($user)->allows('addToCart', $article)) {
            $this->addError('cart', __('ui.assistant_cart_unavailable'));

            return;
        }

        $user->cart()->syncWithoutDetaching([$article->id]);
        // Aggiorna il contatore nella navbar senza ricaricare la pagina (resources/js/script.js)
        $this->dispatch('cart-updated', count: $user->cart()->count());
    }

    public function resetConversation(): void
    {
        $this->messages = [];
        $this->conversationId = null;
        $this->resetErrorBag();
        $this->persist();
    }

    /**
     * Schede degli annunci ricostruite dal database: solo annunci pubblicati, link e foto generati da Laravel.
     * Si salva una "fotografia", così ridisegnare la chat non richiede altre query.
     *
     * @param  list<int>  $ids
     * @return list<array<string, mixed>>
     */
    private function cards(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        $articles = Article::accepted()->with('images')->whereKey($ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn (int $id) => $articles->get($id))
            ->filter()
            ->map(fn (Article $article) => [
                'id' => $article->id,
                'title' => $article->title,
                'price' => number_format((float) $article->price, 2, ',', '.'),
                'url' => route('articles.show', $article),
                'image' => $article->images->first()?->thumbnailUrl() ?? asset('media/placeholder.svg'),
            ])
            ->values()
            ->all();
    }

    // Il modello a volte usa il grassetto Markdown: la chat mostra testo semplice
    private function cleanReply(string $reply): string
    {
        return Str::of($reply)->replace(['**', '__'], '')->trim()->toString();
    }

    private function persist(): void
    {
        $this->messages = array_slice($this->messages, -self::MAX_STORED_MESSAGES);

        session()->put(self::SESSION_KEY, [
            'messages' => $this->messages,
            'conversation_id' => $this->conversationId,
            'open' => $this->open,
        ]);
    }

    public function render()
    {
        return view('livewire.shopping-assistant', [
            // Per mostrare "nel carrello" sulle schede già aggiunte (una query, solo se la chat è aperta)
            'cartIds' => $this->open && auth()->check() ? auth()->user()->cart()->pluck('articles.id')->all() : [],
        ]);
    }
}
