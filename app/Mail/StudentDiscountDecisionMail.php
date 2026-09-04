<?php

namespace App\Mail;

use App\Models\StudentDiscountClaim;
use App\Models\StudentDiscountCode;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class StudentDiscountDecisionMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $renderedSubject;

    public string $renderedBody;

    public bool $isTest = false;

    public function __construct(
        public StudentDiscountClaim $claim,
        public ?StudentDiscountCode $discountCode,
        public array $renderedTemplate,
    ) {
        $this->renderedSubject = (string) ($renderedTemplate['subject'] ?? 'Your student discount update');
        $this->renderedBody = (string) ($renderedTemplate['body'] ?? '');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->renderedSubject);
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.student-discount-decision',
            text: 'mail.student-discount-decision-text',
        );
    }
}
