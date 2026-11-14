<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAvatarRequest;
use App\Models\Article;
use App\Models\Message;
use App\Models\PurchaseRequest;
use App\Models\Review;
use App\Services\AccountEraser;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\Image\Enums\ImageDriver;
use Spatie\Image\Image;

class ProfileController extends Controller
{
    public const AVATAR_SIZE = 256;

    public function show(Request $request)
    {
        $user = $request->user();

        $articles = $user->articles()->with(['images', 'category'])->latest()->get();

        // whereStrict: con where() il confronto è "==" e null (in revisione) verrebbe contato come false (rifiutato)
        $counts = [
            'published' => $articles->whereStrict('is_accepted', true)->count(),
            'pending' => $articles->whereNull('is_accepted')->count(),
            'rejected' => $articles->whereStrict('is_accepted', false)->count(),
        ];

        return view('profile.show', compact('user', 'articles', 'counts'));
    }

    // Validazione in UpdateAvatarRequest
    public function updateAvatar(UpdateAvatarRequest $request)
    {
        $user = $request->user();
        $disk = Storage::disk('public');
        $path = "avatars/{$user->id}/".Str::random(40).'.jpg';

        // Ritaglio quadrato e ricodifica in JPEG: immagine leggera e senza metadati EXIF (es. posizione GPS)
        $disk->makeDirectory(dirname($path));
        Image::useImageDriver(ImageDriver::Gd)
            ->load($request->file('avatar')->getRealPath())
            ->fit(Fit::Crop, self::AVATAR_SIZE, self::AVATAR_SIZE)
            ->background('#0f172a') // i PNG trasparenti non diventano neri nel JPEG
            ->quality(85)
            ->save($disk->path($path));

        $this->deleteAvatarFile($user->avatar);
        $user->avatar = $path;
        $user->save();

        return redirect()->route('profile.show')->with('message', __('ui.avatar_updated'));
    }

    public function destroyAvatar(Request $request)
    {
        $user = $request->user();

        $this->deleteAvatarFile($user->avatar);
        $user->avatar = null;
        $user->save();

        return redirect()->route('profile.show')->with('message', __('ui.avatar_removed'));
    }

    // Copia dei propri dati (GDPR): dell'altra persona solo il nome, già visibile sul sito
    public function export(Request $request): JsonResponse
    {
        $user = $request->user();

        $order = fn (PurchaseRequest $purchase) => [
            'status' => $purchase->status,
            'total' => $purchase->total,
            'created_at' => $purchase->created_at,
            'items' => $purchase->items->map->only(['title', 'price'])->values(),
        ];

        return response()->json([
            'profile' => [
                ...$user->only(['name', 'email', 'locale', 'is_revisor', 'created_at']),
                'avatar' => $user->avatarUrl(),
            ],
            'articles' => $user->articles()->with(['images', 'category'])->get()->map(fn (Article $article) => [
                ...$article->only(['title', 'description', 'price', 'is_accepted', 'reserved_at', 'sold_at', 'created_at']),
                'category' => $article->category?->name,
                'images' => $article->images->map(fn ($image) => url($image->getDisplayUrl()))->values(),
            ]),
            'orders' => $user->purchaseRequests()->with(['items', 'seller'])->get()->map(fn (PurchaseRequest $purchase) => [
                ...$order($purchase),
                'seller' => $purchase->seller?->name,
                'message' => $purchase->message,
            ]),
            'sales' => $user->receivedPurchaseRequests()->with(['items', 'buyer'])->get()->map(fn (PurchaseRequest $purchase) => [
                ...$order($purchase),
                'buyer' => $purchase->buyer?->name,
            ]),
            'messages' => Message::where('user_id', $user->id)->get(['purchase_request_id', 'body', 'created_at']),
            'reviews_written' => Review::where('reviewer_id', $user->id)->get(['rating', 'comment', 'created_at']),
            'reviews_received' => $user->reviewsReceived()->get(['rating', 'comment', 'created_at']),
        ])->header('Content-Disposition', 'attachment; filename="presto-i-miei-dati.json"');
    }

    public function destroy(Request $request, AccountEraser $eraser)
    {
        $request->validateWithBag('deleteAccount', ['password' => ['required', 'string', 'current_password:web']]);

        $user = $request->user();
        Auth::logout();
        $eraser->erase($user);
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('homepage')->with('message', __('ui.account_deleted'));
    }

    private function deleteAvatarFile(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
