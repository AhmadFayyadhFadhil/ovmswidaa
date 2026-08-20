<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;

class RequestNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public array $emailData;

    /**
     * Create a new message instance.
     */
    public function __construct(array $emailData)
    {
        $this->emailData = $emailData;
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->emailData['subjectTitle'] ?? '[OVMS Widatra] Pemberitahuan Permohonan Kendaraan',
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.request-notification',
            text: 'emails.request-notification-text',
            with: $this->emailData,
        );
    }

    /**
     * Get the message headers for anti-spam deliverability.
     */
    public function headers(): Headers
    {
        return new Headers(
            text: [
                'X-Auto-Response-Suppress' => 'All',
                'Auto-Submitted'           => 'auto-generated',
                'X-Mailer'                 => 'OVMS-Widatra-Mailer/2.0',
                'Precedence'               => 'bulk',
            ],
        );
    }

    /**
     * Get the attachments for the message.
     *
     * @return array<int, \Illuminate\Mail\Mailables\Attachment>
     */
    public function attachments(): array
    {
        return [];
    }
}
