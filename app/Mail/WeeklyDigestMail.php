<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Support\Facades\URL;

class WeeklyDigestMail extends Mailable
{
    public string $unsubscribeUrl;

    /** @param array<string, mixed> $report */
    public function __construct(public readonly User $user, public readonly array $report, public readonly string $reportUrl)
    {
        $this->unsubscribeUrl = URL::signedRoute('digest.unsubscribe', ['user' => $user->id]);
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: config('app.name').' weekly report: '.$this->report['period']['label']);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.digest');
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->unsubscribeUrl.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
