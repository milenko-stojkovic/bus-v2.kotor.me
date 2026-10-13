<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class FreeReservationRequestRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $agencyLocale,
        public readonly string $institutionName,
        public readonly int $requestId,
        public readonly string $reservationDate,
        public readonly string $rejectionReason,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->agencyLocale === 'en'
            ? 'Free reservation request was rejected'
            : 'Zahtjev za besplatnu rezervaciju je odbijen';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.free-reservation-request-rejected');
    }
}
