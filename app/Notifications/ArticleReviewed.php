<?php

namespace App\Notifications;

use App\Models\Article;
use App\Notifications\Concerns\RetriesDelivery;
use App\Support\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\SerializesModels;

/**
 * Email all'autore con l'esito della revisione. Inviata in coda (il revisore non aspetta il server di posta)
 * e nella lingua dell'autore (User::preferredLocale()).
 */
class ArticleReviewed extends Notification implements ShouldQueue
{
    use Queueable, RetriesDelivery, SerializesModels;

    // Annuncio eliminato prima dell'invio: il job viene scartato invece di finire tra i falliti
    public bool $deleteWhenMissingModels = true;

    // Istante della decisione: se l'annuncio viene modificato dopo (es. decisione annullata) l'email non parte
    public int $decidedAt;

    public function __construct(public Article $article, public bool $accepted)
    {
        $this->decidedAt = $article->updated_at?->getTimestamp() ?? now()->getTimestamp();
        // Parte solo dopo il commit: il job trova l'annuncio con lo stato già aggiornato
        $this->afterCommit();
    }

    /**
     * Controllato da Laravel al momento dell'invio (dopo il ritardo in coda):
     * niente email se il revisore ha annullato o cambiato la decisione, o se l'annuncio è stato eliminato.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $article = $this->article->fresh();

        return $article !== null
            && $article->is_accepted === $this->accepted
            && $article->updated_at->getTimestamp() === $this->decidedAt;
    }

    /**
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $status = $this->accepted ? 'accepted' : 'rejected';
        $mail = (new MailMessage)
            ->subject(__("ui.review_mail_{$status}_subject", ['title' => $this->article->title]))
            ->greeting(__('ui.review_mail_greeting', ['name' => $notifiable->name]))
            ->line(__("ui.review_mail_{$status}_line", ['title' => MailText::plain($this->article->title)]));

        if ($this->accepted) {
            return $mail->action(__('ui.review_mail_view_ad'), route('articles.show', $this->article));
        }

        // Rifiutato: l'autore può correggerlo, e la modifica lo rimette in revisione
        return $mail
            ->line(__('ui.review_mail_rejected_hint'))
            ->action(__('ui.edit_ad'), route('articles.edit', $this->article));
    }
}
