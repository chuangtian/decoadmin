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

    public function __construct(
        public StudentDiscountClaim $claim,
        public ?StudentDiscountCode $discountCode,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->discountCode ? '你的学生优惠码已生成' : '学生优惠申请审核结果');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.student-discount-decision');
    }
}
