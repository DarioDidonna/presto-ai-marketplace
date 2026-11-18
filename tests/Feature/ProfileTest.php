<?php

namespace Tests\Feature;

use App\Models\Article;
use App\Models\PurchaseRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('profile.show'))->assertRedirect(route('login'));
        $this->post(route('profile.avatar.update'))->assertRedirect(route('login'));
        $this->delete(route('profile.avatar.destroy'))->assertRedirect(route('login'));
    }

    public function test_profile_shows_user_data_and_only_own_articles_with_status(): void
    {
        $user = User::factory()->create(['name' => 'Giulia Bianchi', 'email' => 'giulia@example.com']);
        Article::factory()->accepted()->for($user)->create(['title' => 'Bici pubblicata']);
        Article::factory()->for($user)->create(['title' => 'Lampada in attesa']);
        Article::factory()->rejected()->for($user)->create(['title' => 'Quadro rifiutato']);
        Article::factory()->accepted()->create(['title' => 'Annuncio di un altro']);

        $response = $this->actingAs($user)->get(route('profile.show'))->assertOk();

        $response->assertSee('Giulia Bianchi')
            ->assertSee('giulia@example.com')
            ->assertSee(__('ui.role_user'))
            ->assertSee('Bici pubblicata')
            ->assertSee('Lampada in attesa')
            ->assertSee('Quadro rifiutato')
            ->assertDontSee('Annuncio di un altro')
            ->assertViewHas('counts', ['published' => 1, 'pending' => 1, 'rejected' => 1]);
    }

    public function test_user_can_upload_avatar_which_is_cropped_and_shown_in_navbar(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('foto.png', 600, 400)])
            ->assertRedirect(route('profile.show'))
            ->assertSessionHas('message', __('ui.avatar_updated'));

        $path = $user->fresh()->avatar;
        $this->assertNotNull($path);
        $this->assertStringStartsWith("avatars/{$user->id}/", $path);
        Storage::disk('public')->assertExists($path);

        [$width, $height, $type] = getimagesize(Storage::disk('public')->path($path));
        $this->assertSame([256, 256, IMAGETYPE_JPEG], [$width, $height, $type]);

        // La navbar mostra la foto al posto delle iniziali
        $this->actingAs($user->fresh())->get('/')->assertSee(Storage::disk('public')->url($path), false);
    }

    public function test_new_avatar_replaces_and_deletes_the_old_file(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $old = $user->fresh()->avatar;

        $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('b.jpg', 300, 300)]);
        $new = $user->fresh()->avatar;

        $this->assertNotSame($old, $new);
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($new);
    }

    public function test_user_can_remove_avatar(): void
    {
        $user = User::factory()->create(['name' => 'Luca Verdi']);
        $this->actingAs($user)->post(route('profile.avatar.update'), ['avatar' => UploadedFile::fake()->image('a.jpg', 300, 300)]);
        $path = $user->fresh()->avatar;

        $this->actingAs($user)->delete(route('profile.avatar.destroy'))->assertRedirect(route('profile.show'));

        $this->assertNull($user->fresh()->avatar);
        Storage::disk('public')->assertMissing($path);
        $this->actingAs($user->fresh())->get('/')->assertSee('LV');
    }

    public function test_invalid_avatar_uploads_are_rejected(): void
    {
        $user = User::factory()->create();

        foreach ([
            UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf'),
            UploadedFile::fake()->image('enorme.jpg', 300, 300)->size(3000),
            UploadedFile::fake()->image('minuscola.jpg', 20, 20),
            UploadedFile::fake()->create('icona.gif', 10, 'image/gif'),
        ] as $file) {
            $this->actingAs($user)
                ->from(route('profile.show'))
                ->post(route('profile.avatar.update'), ['avatar' => $file])
                ->assertRedirect(route('profile.show'))
                ->assertSessionHasErrors('avatar');
        }

        $this->assertNull($user->fresh()->avatar);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_avatar_cannot_be_set_through_registration(): void
    {
        $this->post('/register', [
            'name' => 'Furbo',
            'email' => 'furbo@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'avatar' => '../../.env',
        ]);

        $this->assertNull(User::where('email', 'furbo@example.com')->first()->avatar);
    }

    public function test_navbar_links_to_profile(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertSee(route('profile.show'), false);
    }

    public function test_quick_actions_depend_on_role(): void
    {
        $this->actingAs(User::factory()->create())->get(route('profile.show'))
            ->assertSee(__('ui.quick_actions'))
            ->assertSee(__('ui.apply_revisor'))
            ->assertSee('action="'.route('revisor.apply').'"', false);

        $this->actingAs(User::factory()->revisor()->create())->get(route('profile.show'))
            ->assertDontSee(__('ui.apply_revisor'))
            ->assertSee('href="'.route('revisor.index').'"', false);
    }

    public function test_user_can_change_password_from_profile(): void
    {
        $user = User::factory()->create(); // password: "password"

        $this->actingAs($user)
            ->from(route('profile.show'))
            ->put(route('user-password.update'), [
                'current_password' => 'password',
                'password' => 'NuovaPassword123!',
                'password_confirmation' => 'NuovaPassword123!',
            ])
            ->assertRedirect(route('profile.show'))
            ->assertSessionHasNoErrors();

        $this->assertTrue(Hash::check('NuovaPassword123!', $user->fresh()->password));

        $this->actingAs($user->fresh())->withSession(['status' => 'password-updated'])
            ->get(route('profile.show'))
            ->assertSee(__('ui.password_updated'));
    }

    public function test_wrong_current_password_shows_italian_error(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->from(route('profile.show'))
            ->put(route('user-password.update'), [
                'current_password' => 'sbagliata',
                'password' => 'NuovaPassword123!',
                'password_confirmation' => 'Diversa123!',
            ])
            ->assertRedirect(route('profile.show'))
            ->assertSessionHasErrorsIn('updatePassword', [
                'current_password' => 'La password attuale non è corretta.',
                'password' => 'La conferma del campo password non corrisponde.',
            ]);

        $this->assertTrue(Hash::check('password', $user->fresh()->password));
    }

    public function test_login_failure_message_is_translated(): void
    {
        $user = User::factory()->create();

        $this->post('/login', ['email' => $user->email, 'password' => 'sbagliata'])
            ->assertSessionHasErrors(['email' => 'Le credenziali inserite non corrispondono ai nostri archivi.']);
    }

    public function test_user_can_change_name_and_email_stays_the_same(): void
    {
        $user = User::factory()->create(['name' => 'Mario Rossi', 'email' => 'mario@example.com']);

        $this->actingAs($user)
            ->from(route('profile.show'))
            ->put(route('user-profile-information.update'), ['name' => '  Mario Bianchi  '])
            ->assertRedirect(route('profile.show'))
            ->assertSessionHasNoErrors();

        $user->refresh();
        $this->assertSame('Mario Bianchi', $user->name);
        $this->assertSame('mario@example.com', $user->email);

        $this->actingAs($user)->withSession(['status' => 'profile-information-updated'])
            ->get(route('profile.show'))
            ->assertSee(__('ui.name_updated'))
            ->assertSee('MB');
    }

    public function test_invalid_name_is_rejected_with_italian_message(): void
    {
        $user = User::factory()->create(['name' => 'Mario Rossi']);

        foreach (['' => 'Il campo nome utente è obbligatorio.', 'A' => 'Il campo nome utente deve contenere almeno 2 caratteri.'] as $name => $message) {
            $this->actingAs($user)
                ->from(route('profile.show'))
                ->put(route('user-profile-information.update'), ['name' => $name])
                ->assertRedirect(route('profile.show'))
                ->assertSessionHasErrorsIn('updateProfileInformation', ['name' => $message]);
        }

        $this->assertSame('Mario Rossi', $user->fresh()->name);
    }

    public function test_name_form_reopens_when_validation_fails(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user)->from(route('profile.show'))->put(route('user-profile-information.update'), ['name' => 'A']);

        $this->actingAs($user)->get(route('profile.show'))
            ->assertSee('id="editNameForm" class="collapse mt-3 show"', false);
    }

    public function test_guest_cannot_change_name(): void
    {
        $this->put(route('user-profile-information.update'), ['name' => 'Hacker'])->assertRedirect(route('login'));
    }

    public function test_export_contains_own_data_only(): void
    {
        $user = User::factory()->create(['email' => 'mia@example.com']);
        $article = Article::factory()->create(['user_id' => $user->id, 'title' => 'Annuncio mio']);
        Article::factory()->create(['title' => 'Annuncio altrui']);
        $buyer = User::factory()->create(['email' => 'acquirente@example.com']);
        PurchaseRequest::factory()->create(['buyer_id' => $buyer->id, 'seller_id' => $user->id]);

        $this->get(route('profile.export'))->assertRedirect(route('login'));

        $response = $this->actingAs($user)->get(route('profile.export'))
            ->assertOk()
            ->assertHeader('Content-Disposition', 'attachment; filename="presto-i-miei-dati.json"')
            ->assertJsonPath('profile.email', 'mia@example.com')
            ->assertJsonPath('articles.0.title', $article->title)
            ->assertJsonCount(1, 'articles')
            ->assertJsonCount(1, 'sales');

        $this->assertStringNotContainsString('acquirente@example.com', $response->getContent());
        $this->assertStringNotContainsString($user->password, $response->getContent());
    }

    public function test_account_deletion_needs_the_password(): void
    {
        $user = User::factory()->create(['password' => 'Password123!']);

        $this->delete(route('profile.destroy'))->assertRedirect(route('login'));
        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'sbagliata'])
            ->assertSessionHasErrorsIn('deleteAccount', 'password');

        $this->assertNull($user->fresh()->anonymized_at);
    }

    public function test_account_deletion_erases_personal_data_and_keeps_the_other_side_working(): void
    {
        Storage::fake('public');
        Notification::fake();
        $user = User::factory()->create(['name' => 'Giulia Bianchi', 'email' => 'giulia@example.com', 'password' => 'Password123!', 'avatar' => 'avatars/1/a.jpg']);
        $other = User::factory()->create();

        $mine = Article::factory()->accepted()->create(['user_id' => $user->id]);
        Storage::disk('public')->put("articles/{$mine->id}/foto.jpg", 'x');
        Storage::disk('public')->put("avatars/{$user->id}/a.jpg", 'x');

        // Sta acquistando un annuncio altrui: deve tornare disponibile
        $reserved = Article::factory()->accepted()->create(['user_id' => $other->id, 'reserved_at' => now()]);
        $pending = PurchaseRequest::factory()->create(['buyer_id' => $user->id, 'seller_id' => $other->id, 'status' => PurchaseRequest::PENDING, 'message' => 'Chiamami al 333']);
        $pending->items()->create(['article_id' => $reserved->id, 'title' => $reserved->title, 'price' => $reserved->price]);

        // Vendita già conclusa: resta nello storico dell'altra persona
        $sold = PurchaseRequest::factory()->create(['buyer_id' => $other->id, 'seller_id' => $user->id, 'status' => PurchaseRequest::ACCEPTED]);
        $sold->messages()->create(['user_id' => $user->id, 'body' => 'Il mio indirizzo è via Roma 1']);

        $this->actingAs($user)->delete(route('profile.destroy'), ['password' => 'Password123!'])
            ->assertRedirect(route('homepage'))
            ->assertSessionHas('message');

        $this->assertGuest();
        $fresh = $user->fresh();
        $this->assertNotNull($fresh->anonymized_at);
        $this->assertSame('Utente eliminato', $fresh->name);
        $this->assertStringEndsWith('@deleted.invalid', $fresh->email);
        $this->assertNull($fresh->avatar);
        $this->assertFalse(Hash::check('Password123!', $fresh->password));

        $this->assertModelMissing($mine);
        Storage::disk('public')->assertMissing("articles/{$mine->id}/foto.jpg");
        Storage::disk('public')->assertMissing("avatars/{$user->id}/a.jpg");

        $this->assertSame(PurchaseRequest::CANCELLED, $pending->fresh()->status);
        $this->assertNull($pending->fresh()->message);
        $this->assertTrue($reserved->fresh()->isAvailable());

        $this->assertModelExists($sold);
        $this->assertSame(0, $sold->messages()->count());
        $this->post('/login', ['email' => 'giulia@example.com', 'password' => 'Password123!'])->assertSessionHasErrors('email');
        $this->get(route('sellers.show', $user))->assertNotFound();
        $this->actingAs($other)->get(route('orders.index'))->assertOk()->assertDontSee('deleted.invalid');
    }
}
