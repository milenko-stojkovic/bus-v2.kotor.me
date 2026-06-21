<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

final class VehicleCategoryChangeRejectedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly string $agencyLocale,
        public readonly string $agencyName,
        public readonly string $licensePlate,
        public readonly string $requestedCategory,
        public readonly string $oldCategory,
        public readonly string $rejectionReason,
    ) {}

    public function envelope(): Envelope
    {
        $subject = $this->agencyLocale === 'en'
            ? 'Vehicle category change request rejected'
            : 'Zahtjev za promjenu kategorije vozila je odbijen';

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.vehicle-category-change-rejected');
    }
}
