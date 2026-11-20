<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The access-request digest — Proposal 149 §111.
 *
 * §110 made the refusal visible on a page. A page is pull, and the people who
 * need it are not in the habit of visiting it, so the list sat there. This is
 * the push: one mail per estate, daily, naming who tried to join and which gate
 * stopped them.
 *
 * What it deliberately is not:
 *
 *  - **Not an alert.** Nothing here is urgent, and an urgent framing for a
 *    low-urgency event is how a channel gets muted. The subject says "waiting",
 *    not "action required", and the body names the page rather than asking for a
 *    reply.
 *  - **Not a decision surface.** It carries no links that approve anything. A
 *    one-click approve in an email would move an estate-boundary decision out of
 *    the audited page (§110.5) and into whoever holds the mailbox.
 *  - **Not cross-estate.** One mail per estate, to that estate's own people. A
 *    combined mail would disclose one customer's join attempts to another's.
 *  - **Not sent when there is nothing to say.** An empty digest teaches the
 *    recipient to ignore the channel, and the "0 pending" mail is exactly the
 *    one that arrives on a quiet week and gets filtered.
 *
 * Plain text, like the magic link (§103): there is no imagery, no button, and
 * nothing that needs a stylesheet to be legible in a terminal or a screen
 * reader.
 */
class AccessDigest extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * @param string $estateName  The estate's display name, for the subject and body.
     * @param string $recipientName The addressee's name.
     * @param int    $total       How many requests are pending, in full.
     * @param list<array{email: string, reason: string, attempted_at: string}> $rows
     *                            The preview — at most `preview_limit` entries.
     * @param string $url         The deep link to the estate's access-requests page.
     */
    public function __construct(
        public readonly string $estateName,
        public readonly string $recipientName,
        public readonly int $total,
        public readonly array $rows,
        public readonly string $url,
    ) {
    }

    public function envelope(): Envelope
    {
        // The count goes in the subject because it is the only part visible in a
        // list of mail: "3 access requests are waiting" is actionable at a
        // glance, where "Access digest for Acme" is not.
        return new Envelope(
            subject: sprintf(
                '%d access %s waiting for %s',
                $this->total,
                1 === $this->total ? 'request is' : 'requests are',
                $this->estateName
            ),
        );
    }

    public function content(): Content
    {
        return new Content(
            text: 'emails.access-digest',
            with: array(
                'name' => $this->recipientName,
                'estate' => $this->estateName,
                'total' => $this->total,
                // The sentence is composed here rather than in the template: a
                // Blade `@if` around prose leaves stray blank lines, and the
                // singular/plural pair is the one thing in this mail that a
                // reader notices when it is wrong.
                'summary' => $this->summary(),
                'rows' => $this->rows,
                'url' => $this->url,
                'remaining' => max(0, $this->total - count($this->rows)),
                'appName' => (string) config('app.name'),
            ),
        );
    }

    /**
     * The opening sentence, agreeing in number with the count.
     */
    public function summary(): string
    {
        if (1 === $this->total) {
            return sprintf('One person has tried to sign in to %s and was not let in.', $this->estateName);
        }

        return sprintf(
            '%d people have tried to sign in to %s and were not let in.',
            $this->total,
            $this->estateName
        );
    }
}
