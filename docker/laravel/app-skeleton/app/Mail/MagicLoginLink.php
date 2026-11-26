<?php

namespace App\Mail;

use App\Support\Auth\MagicLinkService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The magic-link sign-in email — Proposal 149 §103.
 *
 * Plain text, one link, one expiry, and an explicit "if this wasn't you". The
 * message names the lifetime because a recipient who opens it an hour later
 * should not conclude the account is broken — and it says what to do about it.
 *
 * Not queued: a sign-in link that arrives after the user has given up is worse
 * than one that fails loudly, and the request handler can revoke the token if
 * the transport refuses. `MAIL_MAILER=array` in tests records it without a
 * transport, which is also what `Mail::fake()` asserts against.
 */
class MagicLoginLink extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $link,
        public readonly string $recipientName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('Your sign-in link for %s', (string) config('app.name')),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.magic-login',
            with: array(
                'link' => $this->link,
                'name' => $this->recipientName,
                'minutes' => MagicLinkService::TTL_MINUTES,
                'appName' => (string) config('app.name'),
            ),
        );
    }
}
