<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The expiry finding, as mail — Proposal 149 §130.
 *
 * Deliberately plain text: this is an operational alert that may be read on a
 * phone at 07:00, and the only thing it has to survive is a mail client that
 * strips HTML. The finding itself is also in `nvoos_activity_events`, so a
 * lost message is recoverable rather than lost.
 */
final class MachineTokensExpiring extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * @param list<array<string, mixed>> $rows
     */
    public function __construct(
        public readonly array $rows,
        public readonly int $days,
        public readonly bool $escalated = false,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf(
                '%s%d machine credential(s) expire within %d days',
                $this->escalated ? 'ESCALATED: ' : '',
                count($this->rows),
                $this->days
            ),
        );
    }

    public function content(): Content
    {
        return new Content(text: 'mail.machine-tokens-expiring');
    }
}
