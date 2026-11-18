<?php

namespace App\Mail;

use App\Models\User;
use App\Notifications\Concerns\RetriesDelivery;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

class BecomeRevisor extends Mailable implements ShouldQueue
{
    use Queueable, RetriesDelivery, SerializesModels;

    public const LINK_DAYS = 3;

    public $user;

    // Su APP_URL e con firma relativa: l'host della richiesta non entra nel link
    public string $approvalUrl;

    /**
     * Create a new message instance.
     */
    public function __construct(User $user)
    {
        $this->user = $user;
        $this->approvalUrl = rtrim((string) config('app.url'), '/')
            .URL::temporarySignedRoute('revisor.confirm', now()->addDays(self::LINK_DAYS), ['user' => $user], absolute: false);
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Rendi revisore l'utente ".$this->user->name,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'mail.become-revisor',
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
