<?php

namespace App\Livewire;

use App\Livewire\Concerns\HandlesArticleForm;
use App\Models\Article;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Component;
use Livewire\WithFileUploads;

class EditArticleForm extends Component
{
    use AuthorizesRequests, HandlesArticleForm, WithFileUploads;

    public Article $article;

    // ID delle foto esistenti da eliminare: applicato solo al salvataggio, così "Annulla" non perde nulla
    public array $imagesToRemove = [];

    public function mount(Article $article)
    {
        $this->authorize('update', $article);

        $this->article = $article;
        $this->form->fillFromArticle($article);
    }

    protected function keptImagesCount(): int
    {
        return $this->article->images()->whereNotIn('id', $this->imagesToRemove)->count();
    }

    public function toggleExistingImage(int $imageId)
    {
        // Solo foto di questo annuncio: l'ID arriva dal browser e potrebbe essere manomesso
        if (! $this->article->images()->whereKey($imageId)->exists()) {
            return;
        }

        if (in_array($imageId, $this->imagesToRemove, true)) {
            $this->imagesToRemove = array_values(array_diff($this->imagesToRemove, [$imageId]));
        } else {
            $this->imagesToRemove[] = $imageId;
        }
    }

    public function update()
    {
        // Ricontrollo: un componente Livewire può ricevere richieste anche dopo il mount
        $this->authorize('update', $this->article);
        $this->validateArticle();

        $this->article->fill($this->form->attributes());
        // Ogni modifica torna in revisione: un annuncio approvato non può cambiare contenuto senza controllo
        $this->article->is_accepted = null;
        $this->article->save();

        $this->article->images()->whereIn('id', $this->imagesToRemove)->get()->each->deleteWithFiles();
        $this->storeNewImages($this->article);

        session()->flash('message', __('ui.article_updated'));

        return $this->redirectRoute('articles.show', $this->article);
    }

    public function render()
    {
        return view('livewire.article-form', [
            'submitAction' => 'update',
            'submitLabel' => __('ui.save_changes'),
            'submitIcon' => 'bi-check2-circle',
            'existingImages' => $this->article->images()->get(),
        ]);
    }
}
