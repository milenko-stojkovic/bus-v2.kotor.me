<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FreeReservationRequestFulfilledMail extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param  list<array{path: string, filename: string}>  $pdfAttachments
     */
    public function __construct(
        public readonly string $bodyText,
        public readonly string $emailSubject,
        public readonly array $pdfAttachments,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailSubject,
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.free-reservation-request-fulfilled-text',
            with: ['body' => $this->bodyText],
        );
    }

    public function build(): static
    {
        foreach ($this->pdfAttachments as $attachment) {
            $this->attach($attachment['path'], [
                'as' => $attachment['filename'],
                'mime' => 'application/pdf',
            ]);
        }

        return $this;
    }
}
