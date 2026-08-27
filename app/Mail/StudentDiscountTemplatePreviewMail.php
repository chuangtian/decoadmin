<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentDiscountTemplatePreviewMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $renderedSubject,
        public string $renderedBody,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Test] '.$this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.student-discount-template-preview');
    }
}
