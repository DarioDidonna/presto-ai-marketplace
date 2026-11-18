<?php

namespace Tests\Feature;

use App\Jobs\RemoveFaces;
use App\Livewire\EditArticleForm;
use App\Models\Article;
use App\Models\Category;
use App\Models\Image;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class ArticleEditDeleteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Queue::fake();
    }

    private function articleWithImages(int $count = 2): Article
    {
        $article = Article::factory()->accepted()->create();
        foreach (range(1, $count) as $n) {
            $path = "articles/{$article->id}/foto{$n}.jpg";
            Storage::disk('public')->put($path, 'x');
            Storage::disk('public')->put("articles/{$article->id}/crop_300x300_foto{$n}.jpg", 'x');
            Storage::disk('public')->put("articles/{$article->id}/crop_800x800_foto{$n}.jpg", 'x');
            $article->images()->create(['path' => $path]);
        }

        return $article;
    }

    public function test_only_the_author_sees_edit_and_delete_buttons(): void
    {
        $article = Article::factory()->accepted()->create();

        $this->actingAs($article->user)->get(route('articles.show', $article))
            ->assertSee(route('articles.edit', $article), false)
            ->assertSee('id="confirmDeleteModal"', false);

        foreach ([User::factory()->create(), User::factory()->revisor()->create()] as $other) {
            $this->actingAs($other)->get(route('articles.show', $article))
                ->assertDontSee(route('articles.edit', $article), false)
                ->assertDontSee('confirmDeleteModal', false);
        }
    }

    public function test_edit_page_is_protected(): void
    {
        $article = Article::factory()->accepted()->create();

        $this->get(route('articles.edit', $article))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->get(route('articles.edit', $article))->assertForbidden();
        $this->actingAs(User::factory()->revisor()->create())->get(route('articles.edit', $article))->assertForbidden();
        $this->actingAs($article->user)->get(route('articles.edit', $article))
            ->assertOk()
            ->assertSeeLivewire(EditArticleForm::class);
    }

    public function test_author_can_edit_and_article_goes_back_to_review(): void
    {
        $article = Article::factory()->accepted()->create();
        $newCategory = Category::factory()->create();

        Livewire::actingAs($article->user)
            ->test(EditArticleForm::class, ['article' => $article])
            ->assertSet('form.title', $article->title)
            ->set('form.title', 'Titolo aggiornato')
            ->set('form.price', 42.5)
            ->set('form.description', 'Descrizione aggiornata e abbastanza lunga')
            ->set('form.category', $newCategory->id)
            ->call('update')
            ->assertHasNoErrors()
            ->assertRedirect(route('articles.show', $article));

        $article->refresh();
        $this->assertSame('Titolo aggiornato', $article->title);
        $this->assertSame('42.50', $article->price);
        $this->assertSame($newCategory->id, $article->category_id);
        $this->assertNull($article->is_accepted);
    }

    public function test_other_users_cannot_use_the_edit_component(): void
    {
        $article = Article::factory()->accepted()->create();

        Livewire::actingAs(User::factory()->create())
            ->test(EditArticleForm::class, ['article' => $article])
            ->assertForbidden();
    }

    public function test_validation_still_applies_when_editing(): void
    {
        $article = Article::factory()->accepted()->create();

        Livewire::actingAs($article->user)
            ->test(EditArticleForm::class, ['article' => $article])
            ->set('form.title', 'abc')
            ->call('update')
            ->assertHasErrors(['form.title' => 'min']);

        $this->assertTrue($article->fresh()->is_accepted);
    }

    public function test_marked_images_are_deleted_only_on_save_and_can_be_restored(): void
    {
        $article = $this->articleWithImages(2);
        [$first, $second] = $article->images->all();

        $component = Livewire::actingAs($article->user)
            ->test(EditArticleForm::class, ['article' => $article])
            ->call('toggleExistingImage', $first->id)
            ->call('toggleExistingImage', $second->id)
            ->call('toggleExistingImage', $second->id) // ripristinata
            ->assertSet('imagesToRemove', [$first->id]);

        // Prima del salvataggio non è cambiato nulla
        Storage::disk('public')->assertExists($first->path);

        $component->call('update')->assertHasNoErrors();

        $this->assertModelMissing($first);
        $this->assertModelExists($second);
        Storage::disk('public')->assertMissing([$first->path, "articles/{$article->id}/crop_300x300_foto1.jpg", "articles/{$article->id}/crop_800x800_foto1.jpg"]);
        Storage::disk('public')->assertExists($second->path);
    }

    public function test_cannot_remove_images_of_another_article(): void
    {
        $article = $this->articleWithImages(1);
        $foreign = $this->articleWithImages(1)->images->first();

        Livewire::actingAs($article->user)
            ->test(EditArticleForm::class, ['article' => $article])
            ->call('toggleExistingImage', $foreign->id)
            ->assertSet('imagesToRemove', [])
            ->call('update');

        $this->assertModelExists($foreign);
    }

    public function test_new_images_are_added_and_processed_within_the_limit(): void
    {
        $article = $this->articleWithImages(5);

        Livewire::actingAs($article->user)
            ->test(EditArticleForm::class, ['article' => $article])
            ->set('temporary_images', [UploadedFile::fake()->image('a.jpg'), UploadedFile::fake()->image('b.jpg')])
            ->assertHasErrors(['temporary_images' => 'max'])
            ->set('temporary_images', [UploadedFile::fake()->image('a.jpg')])
            ->assertHasNoErrors()
            ->call('update')
            ->assertHasNoErrors();

        $this->assertSame(6, $article->images()->count());
        Queue::assertPushed(RemoveFaces::class, 1);
    }

    public function test_author_can_delete_article_with_its_files(): void
    {
        $article = $this->articleWithImages(2);

        $this->actingAs($article->user)
            ->delete(route('articles.destroy', $article))
            ->assertRedirect(route('profile.show'))
            ->assertSessionHas('message');

        $this->assertModelMissing($article);
        $this->assertSame(0, Image::count());
        $this->assertSame([], Storage::disk('public')->allFiles("articles/{$article->id}"));
    }

    public function test_others_cannot_delete_article(): void
    {
        $article = Article::factory()->accepted()->create();

        $this->delete(route('articles.destroy', $article))->assertRedirect(route('login'));
        $this->actingAs(User::factory()->create())->delete(route('articles.destroy', $article))->assertForbidden();
        $this->actingAs(User::factory()->revisor()->create())->delete(route('articles.destroy', $article))->assertForbidden();

        $this->assertModelExists($article);
    }
}
