<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * One plain-text mail to a customer: a subject and a body, nothing designed.
 *
 * A Mailable rather than Mail::raw so a test can assert it was sent and to whom. Plain text
 * on purpose: the sign-in mail that went out as a bare link scored NEURAL_SPAM 2.99 at the
 * receiving end, and the cure for that is a mail that reads like a person wrote it, not a
 * template with a logo.
 */
class CustomerNotice extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $subjectLine, public string $body) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.plain', with: ['body' => $this->body]);
    }
}
