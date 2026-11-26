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

	/** The most files one message may carry. */
	private const MAX_ATTACHMENTS = 5;

	/** The total raw bytes one message may attach. */
	private const MAX_ATTACHMENT_BYTES = 5242880;

	/**
	 * Send one message.
	 *
	 * @param array<string, mixed> $message Keys: to, subject, body, is_html, attachments.
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
			new OutboundMessage(
				$subject,
				$body,
				! empty( $message['is_html'] ),
				$this->attachments( $message['attachments'] ?? array() )
			)
		);

		return array( 'success' => true, 'to' => $to );
	}

	/**
	 * Normalise the attachment list a caller supplied.
	 *
	 * Entries without a name or content are dropped rather than sent as empty
	 * files, and the name is flattened to a single path segment so a caller
	 * cannot traverse out of the mailer's temp directory. The count and total
	 * size are capped because the list comes from tool arguments.
	 *
	 * @param mixed $raw Attachment list.
	 * @return list<array{name: string, mime: string, content: string}>
	 */
	private function attachments( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		$normalised = array();
		$bytes      = 0;

		foreach ( $raw as $attachment ) {
			if ( ! is_array( $attachment ) ) {
				continue;
			}

			$name    = isset( $attachment['name'] ) && is_string( $attachment['name'] ) ? trim( $attachment['name'] ) : '';
			$content = isset( $attachment['content'] ) && is_string( $attachment['content'] ) ? $attachment['content'] : '';
			$mime    = isset( $attachment['mime'] ) && is_string( $attachment['mime'] ) ? trim( $attachment['mime'] ) : '';

			if ( '' === $name || '' === $content ) {
				continue;
			}

			$bytes += strlen( $content );

			if ( $bytes > self::MAX_ATTACHMENT_BYTES ) {
				continue;
			}

			$normalised[] = array(
				'name'    => str_replace( array( '/', '\\', "\0" ), '-', $name ),
				'mime'    => '' === $mime ? 'application/octet-stream' : $mime,
				'content' => $content,
			);

			if ( count( $normalised ) >= self::MAX_ATTACHMENTS ) {
				break;
			}
		}

		return $normalised;
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
