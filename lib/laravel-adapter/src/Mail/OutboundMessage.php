<?php
/**
 * A message whose body was authored by a tool, not by a Blade view.
 *
 * `send_group_email` hands over a subject and a body at runtime, so there is no
 * view to render and no layout to inherit. The body is escaped before the
 * plain-text branch adds line breaks, because a tool argument is user input and
 * an HTML mail client will render whatever it is given.
 *
 * Not queued: the tool's contract counts a recipient as sent only when its
 * `send()` call does not throw, so queuing would move the failure past the
 * point where it can be reported.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OutboundMessage extends Mailable {

	use Queueable;
	use SerializesModels;

	public function __construct(
		public readonly string $subjectLine,
		public readonly string $body,
		public readonly bool $isHtml = false,
	) {
	}

	public function envelope(): Envelope {
		return new Envelope( subject: $this->subjectLine );
	}

	public function content(): Content {
		return new Content(
			htmlString: $this->isHtml ? $this->body : nl2br( e( $this->body ) ),
		);
	}
}
