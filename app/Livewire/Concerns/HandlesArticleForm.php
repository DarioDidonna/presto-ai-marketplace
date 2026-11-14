<?php

namespace App\Livewire\Concerns;

use App\Livewire\Forms\ArticleForm;
use App\Models\Article;
use App\Support\PhotoMetadata;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Form dell'annuncio (ArticleForm) e gestione delle nuove foto, condivisi da creazione e modifica.
 */
trait HandlesArticleForm
{
    public const MAX_IMAGES = 6;

    public ArticleForm $form;

    public $images = [];

    public $temporary_images = [];

    // Foto già salvate che restano sull'annuncio (0 in creazione)
    protected function keptImagesCount(): int
    {
        return 0;
    }

    protected function maxNewImages(): int
    {
        return max(0, self::MAX_IMAGES - $this->keptImagesCount());
    }

    // Regole delle sole foto: quelle dei campi testuali stanno in ArticleForm
    protected function rules(): array
    {
        return [
            'images' => 'array|max:'.$this->maxNewImages(),
            'images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
        ];
    }

    protected function messages(): array
    {
        $maxImages = __('ui.validation_images_max_count', ['max' => self::MAX_IMAGES]);

        return [
            'images.max' => $maxImages,
            'images.*.image' => __('ui.validation_image_image'),
            'images.*.mimes' => __('ui.validation_image_image'),
            'images.*.max' => __('ui.validation_image_max'),
            'temporary_images.*.image' => __('ui.validation_image_image'),
            'temporary_images.*.mimes' => __('ui.validation_image_image'),
            'temporary_images.*.max' => __('ui.validation_image_max'),
            'temporary_images.max' => $maxImages,
        ];
    }

    // Validazione in tempo reale dei campi testuali (form.title, form.price, ...)
    public function updated($property)
    {
        if (str_starts_with($property, 'form.')) {
            $this->form->validateOnly(substr($property, strlen('form.')));
        }
    }

    // Valida campi testuali e foto in un'unica passata, così l'utente vede tutti gli errori insieme
    protected function validateArticle(): void
    {
        $this->validate(
            [...$this->form->prefixedRules(), ...$this->rules()],
            [...$this->form->prefixedMessages(), ...$this->messages()],
        );
    }

    public function updatedTemporaryImages()
    {
        // Senza questo, l'errore di un caricamento rifiutato restava visibile anche dopo uno valido
        $this->resetErrorBag(['temporary_images', 'temporary_images.*', 'images', 'images.*']);

        try {
            $this->validate([
                'temporary_images' => 'array|max:'.($this->maxNewImages() - count($this->images)),
                'temporary_images.*' => 'image|mimes:jpg,jpeg,png,webp|max:5120',
            ]);
        } catch (ValidationException $e) {
            // I file scartati non devono restare in coda: si sommerebbero al caricamento successivo
            $this->temporary_images = [];
            throw $e;
        }

        foreach ($this->temporary_images as $image) {
            $this->images[] = $image;
        }

        $this->temporary_images = [];
    }

    public function removeImage($key)
    {
        if (in_array($key, array_keys($this->images))) {
            unset($this->images[$key]);
            $this->images = array_values($this->images);
        }
    }

    // Salva le nuove foto senza metadati (il GPS rivelerebbe dove abita chi vende) e avvia la catena di elaborazione
    // (volti oscurati, miniature con watermark)
    protected function storeNewImages(Article $article): void
    {
        $disk = Storage::disk('public');

        foreach ($this->images as $image) {
            $path = $image->store("articles/{$article->id}", 'public');

            if (! PhotoMetadata::strip($disk->path($path))) {
                logger()->warning("Foto scartata per l'annuncio {$article->id}: file non leggibile");
                $disk->delete($path);

                continue;
            }

            $article->images()->create(['path' => $path])->process();
        }
    }
}
