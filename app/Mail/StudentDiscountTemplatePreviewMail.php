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

    public string $renderedSubject;

    public string $renderedBody;

    public bool $isTest = true;

    public function __construct(public array $renderedTemplate)
    {
        $this->renderedSubject = (string) ($renderedTemplate['subject'] ?? 'Student discount email preview');
        $this->renderedBody = (string) ($renderedTemplate['body'] ?? '');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '[Test] '.$this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.student-discount-template-preview',
            text: 'mail.student-discount-decision-text',
        );
    }
}
