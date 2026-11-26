<?php
/**
 * EmailService tests.
 *
 * The readiness manifest is shared across runtimes, so the package must be able
 * to construct every servable tool. `send_group_email` needs
 * EmailServiceInterface, which is why the binding lives in the package provider
 * rather than in a host app - ToolReadinessRegistrationTest enforces exactly
 * that, and it is what caught the first attempt at this change.
 *
 * @package Nvoos\Laravel\Tests
 * @since   1.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Tests;

use Illuminate\Support\Facades\Mail;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Laravel\Adapter\EmailService;
use Nvoos\Laravel\Mail\OutboundMessage;

final class EmailServiceTest extends TestCase {

	public function test_the_provider_binds_the_email_contract(): void {
		$this->assertInstanceOf(
			EmailService::class,
			$this->app->make( EmailServiceInterface::class ),
		);
	}

	public function test_it_reports_an_unconfigured_mailer_as_unavailable(): void {
		$this->app['config']->set( 'mail.default', '' );

		$this->assertFalse( $this->app->make( EmailServiceInterface::class )->isAvailable() );
	}

	public function test_recipient_validation_matches_the_wordpress_rule(): void {
		$service = $this->app->make( EmailServiceInterface::class );

		$this->assertTrue( $service->validateRecipient( 'someone@example.com' ) );
		$this->assertFalse( $service->validateRecipient( 'not-an-address' ) );
		$this->assertFalse( $service->validateRecipient( '' ) );
	}

	public function test_it_sends_through_the_mailer(): void {
		Mail::fake();

		$result = $this->app->make( EmailServiceInterface::class )->send(
			array(
				'to'      => 'one@example.com',
				'subject' => 'Hi',
				'body'    => 'Body',
			),
		);

		$this->assertTrue( $result['success'] );
		Mail::assertSent( OutboundMessage::class, 1 );
	}

	public function test_attachments_reach_the_mailable(): void {
		Mail::fake();

		$result = $this->app->make( EmailServiceInterface::class )->send(
			array(
				'to'          => 'one@example.com',
				'subject'     => 'Invite',
				'body'        => 'Body',
				'attachments' => array(
					array(
						'name'    => 'appointment-1.ics',
						'mime'    => 'text/calendar',
						'content' => "BEGIN:VCALENDAR\r\nEND:VCALENDAR\r\n",
					),
				),
			),
		);

		$this->assertTrue( $result['success'] );

		Mail::assertSent(
			OutboundMessage::class,
			static function ( OutboundMessage $mail ): bool {
				return 1 === count( $mail->fileAttachments )
					&& 'appointment-1.ics' === $mail->fileAttachments[0]['name']
					&& 'text/calendar' === $mail->fileAttachments[0]['mime'];
			}
		);
	}

	public function test_an_unusable_attachment_is_dropped_and_its_name_flattened(): void {
		Mail::fake();

		$this->app->make( EmailServiceInterface::class )->send(
			array(
				'to'          => 'one@example.com',
				'subject'     => 'Hi',
				'body'        => 'Body',
				'attachments' => array(
					array(
						'name'    => '',
						'mime'    => 'text/plain',
						'content' => 'x',
					),
					array(
						'name'    => '../escape.ics',
						'mime'    => '',
						'content' => 'y',
					),
				),
			),
		);

		Mail::assertSent(
			OutboundMessage::class,
			static function ( OutboundMessage $mail ): bool {
				return 1 === count( $mail->fileAttachments )
					&& '..-escape.ics' === $mail->fileAttachments[0]['name']
					&& 'application/octet-stream' === $mail->fileAttachments[0]['mime'];
			}
		);
	}

	public function test_an_invalid_message_is_refused_without_sending(): void {
		Mail::fake();

		$result = $this->app->make( EmailServiceInterface::class )->send(
			array(
				'to'      => 'not-an-address',
				'subject' => 'Hi',
				'body'    => 'Body',
			),
		);

		$this->assertFalse( $result['success'] );
		Mail::assertNothingSent();
	}
}
