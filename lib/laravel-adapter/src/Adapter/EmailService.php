<?php
/**
 * Laravel adapter: EmailServiceInterface implementation.
 *
 * Wraps Laravel's mailer behind the framework-agnostic email contract so
 * `send_group_email` behaves the same way in both runtimes: validate the
 * recipient, send one message, report what happened.
 *
 * The binding lives in the package provider rather than the host app because
 * the readiness manifest is shared: a row marked servable must be constructible
 * from the package container, and ToolReadinessRegistrationTest enforces it.
 *
 * Exceptions are deliberately left to propagate. The tool counts a recipient as
 * sent unless `send()` throws, and a transport failure that returned quietly
 * would be reported as a delivered email.
 *
 * @package Nvoos\Laravel
 * @since   1.6.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\Mail;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Laravel\Mail\OutboundMessage;

class EmailService implements EmailServiceInterface {

	/**
	 * Send one message.
	 *
	 * @param array<string, mixed> $message Keys: to, subject, body, is_html.
	 * @return array<string, mixed>
	 */
	public function send( array $message ): array {
		$to      = (string) ( $message['to'] ?? '' );
		$subject = (string) ( $message['subject'] ?? '' );
		$body    = (string) ( $message['body'] ?? '' );

		if ( ! $this->validateRecipient( $to ) || '' === $subject ) {
			return array( 'success' => false, 'error' => 'invalid message' );
		}

		Mail::to( $to )->send(
			new OutboundMessage( $subject, $body, ! empty( $message['is_html'] ) )
		);

		return array( 'success' => true, 'to' => $to );
	}

	/**
	 * Whether a mailer is configured.
	 *
	 * Laravel always names a default mailer; in tests `Mail::fake()` swaps the
	 * implementation without changing the config, so this stays true and the
	 * caller reaches the send path where the fake records it.
	 */
	public function isAvailable(): bool {
		return '' !== (string) config( 'mail.default' );
	}

	public function validateRecipient( string $email ): bool {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}
}
