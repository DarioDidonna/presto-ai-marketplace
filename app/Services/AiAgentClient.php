<?php

namespace App\Services;

use App\Models\Article;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Image as SpatieImage;

/**
 * Client del servizio Python dell'agente (ai-agent/). Il browser non lo chiama mai direttamente: passa da qui.
 */
class AiAgentClient
{
    public const DESCRIBE_SIZE = 768;

    public function isEnabled(): bool
    {
        return (string) config('services.ai_agent.token') !== '';
    }

    /**
     * @return array{reply: string, conversation_id: string, article_ids: list<int>}
     *
     * @throws AgentUnavailableException agente spento, Ollama spento o risposta non valida
     */
    public function chat(string $message, ?string $conversationId, string $lang): array
    {
        try {
            $response = $this->http()
                ->post('/chat', array_filter([
                    'message' => $message,
                    'conversation_id' => $conversationId,
                    'lang' => $lang,
                ]))
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $error) {
            throw new AgentUnavailableException('Assistente non disponibile: '.$error->getMessage(), previous: $error);
        }

        if (! is_array($response) || ! is_string($response['reply'] ?? null) || ! is_string($response['conversation_id'] ?? null)) {
            throw new AgentUnavailableException('Risposta non valida dall\'assistente.');
        }

        return [
            'reply' => $response['reply'],
            'conversation_id' => $response['conversation_id'],
            // Solo gli ID: dati, link e foto degli annunci li ricostruisce Laravel dal proprio database
            'article_ids' => collect($response['articles'] ?? [])->pluck('id')->filter(fn ($id) => is_int($id))->values()->all(),
        ];
    }

    /**
     * Parere sull'annuncio per il revisore (solo un suggerimento: decide sempre il revisore).
     *
     * @return array{recommendation: string, confidence: float, reasons: list<string>, flags: list<array{code: string, blocking: bool}>, model_used: bool}
     *
     * @throws AgentUnavailableException
     */
    public function review(Article $article, string $lang): array
    {
        $article->loadMissing(['images', 'category']);

        try {
            $response = $this->http()
                ->post('/review', [
                    'title' => Str::limit($article->title, 200, ''),
                    'description' => Str::limit($article->description, 5000, ''),
                    'price' => (float) $article->price,
                    'category' => $article->category?->name,
                    'photos' => min($article->images->count(), 20),
                    'lang' => $lang,
                ])
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $error) {
            throw new AgentUnavailableException('Assistente non disponibile: '.$error->getMessage(), previous: $error);
        }

        if (! is_array($response) || ! in_array($response['recommendation'] ?? null, ['accept', 'reject', 'unsure'], true)) {
            throw new AgentUnavailableException('Risposta non valida dall\'assistente.');
        }

        return [
            'recommendation' => $response['recommendation'],
            'confidence' => (float) ($response['confidence'] ?? 0),
            'reasons' => array_values(array_filter($response['reasons'] ?? [], 'is_string')),
            'flags' => array_values(array_filter($response['flags'] ?? [], fn ($flag) => is_array($flag) && is_string($flag['code'] ?? null))),
            'model_used' => (bool) ($response['model_used'] ?? false),
        ];
    }

    /**
     * Bozza di annuncio da una foto: il servizio restituisce solo fatti visibili, composti in titolo e descrizione.
     * La foto viene ridotta a 768 px e ricodificata in JPEG prima dell'invio (più veloce, senza metadati EXIF).
     *
     * @param  list<string>  $categories  nomi delle categorie del sito: il modello può proporre solo una di queste
     * @return array{found: bool, title: string, description: string, category: ?string}
     *
     * @throws AgentUnavailableException
     */
    public function describe(string $imagePath, array $categories, string $lang): array
    {
        $resized = tempnam(sys_get_temp_dir(), 'presto-describe-').'.jpg';

        try {
            SpatieImage::useImageDriver(ImageDriver::Gd)
                ->load($imagePath)
                ->fit(Fit::Max, self::DESCRIBE_SIZE, self::DESCRIBE_SIZE)
                ->background('#ffffff')
                ->quality(85)
                ->save($resized);

            $response = $this->http()
                ->post('/describe', [
                    'image' => base64_encode((string) file_get_contents($resized)),
                    'categories' => $categories,
                    'lang' => $lang,
                ])
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $error) {
            throw new AgentUnavailableException('Assistente non disponibile: '.$error->getMessage(), previous: $error);
        } finally {
            @unlink($resized);
        }

        if (! is_array($response) || ! is_bool($response['found'] ?? null)) {
            throw new AgentUnavailableException('Risposta non valida dall\'assistente.');
        }

        $category = $response['category'] ?? null;

        return [
            'found' => $response['found'],
            'title' => Str::limit((string) ($response['title'] ?? ''), 100, ''),
            'description' => Str::limit((string) ($response['description'] ?? ''), 5000, ''),
            // Solo una categoria inviata da noi: tutto il resto viene ignorato
            'category' => is_string($category) && in_array($category, $categories, true) ? $category : null,
        ];
    }

    /**
     * Stato del servizio Python (GET /health, senza token): null se non risponde entro un secondo.
     * Usato dal footer: non deve mai rallentare la pagina.
     *
     * @return array{status: string, model_available: bool}|null
     */
    public function health(): ?array
    {
        try {
            $response = Http::baseUrl(config('services.ai_agent.url'))->acceptJson()->timeout(1)->connectTimeout(1)->get('/health');
        } catch (ConnectionException) {
            return null;
        }

        if (! $response->ok() || ! is_string($response->json('status'))) {
            return null;
        }

        return ['status' => $response->json('status'), 'model_available' => (bool) $response->json('model_available')];
    }

    /**
     * Volti da oscurare in una foto (rilevatore locale del servizio Python, non serve Ollama).
     * Riquadri già allargati, in pixel della foto inviata.
     *
     * @return array{width: int, height: int, faces: list<array{x: int, y: int, width: int, height: int}>}
     *
     * @throws AgentUnavailableException
     */
    public function faces(string $imagePath): array
    {
        try {
            $response = $this->http()
                ->timeout(30)
                ->post('/faces', ['image' => base64_encode((string) file_get_contents($imagePath))])
                ->throw()
                ->json();
        } catch (ConnectionException|RequestException $error) {
            throw new AgentUnavailableException('Rilevatore dei volti non disponibile: '.$error->getMessage(), previous: $error);
        }

        if (! is_array($response) || ! is_int($response['width'] ?? null) || ! is_int($response['height'] ?? null) || ! is_array($response['faces'] ?? null)) {
            throw new AgentUnavailableException('Risposta non valida dal rilevatore dei volti.');
        }

        $faces = array_values(array_filter(
            $response['faces'],
            fn ($face) => is_array($face) && collect(['x', 'y', 'width', 'height'])->every(fn ($key) => is_int($face[$key] ?? null)),
        ));

        return ['width' => $response['width'], 'height' => $response['height'], 'faces' => $faces];
    }

    private function http(): PendingRequest
    {
        return Http::baseUrl(config('services.ai_agent.url'))
            ->withToken(config('services.ai_agent.token'))
            ->acceptJson()
            // Servizio spento o irraggiungibile: errore in 2 secondi, senza tenere ferma la pagina
            ->connectTimeout(2)
            // Un modello locale piccolo risponde in pochi secondi, ma la prima richiesta deve caricarlo in memoria
            ->timeout(60);
    }
}
