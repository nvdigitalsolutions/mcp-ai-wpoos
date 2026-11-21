<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Mail;
use Nvoos\Core\Domain\Contract\EmailServiceInterface;
use Nvoos\Core\Tool\SendGroupEmailTool;
use Nvoos\Core\Tool\SendGroupEmailValidatedTool;
use Nvoos\Laravel\Adapter\EmailService;
use Nvoos\Laravel\Mail\OutboundMessage;
use Tests\TestCase;

/**
 * `send_group_email` used to be unbuildable here: it requires
 * `EmailServiceInterface`, which had no implementation or binding, and
 * `ManifestToolRegistrar` skips such a constructor silently. With
 * `App\Support\Mail\EmailService` bound, both rows construct and send through
 * Laravel's mailer (Proposal 149 §138).
 *
 * `Mail::fake()` is the transport the test asserts against, so nothing leaves
 * the machine.
 */
class SendGroupEmailToolTest extends TestCase
{
    public function test_the_contract_resolves_to_the_laravel_adapter(): void
    {
        $service = $this->app->make(EmailServiceInterface::class);

        $this->assertInstanceOf(EmailService::class, $service);
        $this->assertTrue($service->isAvailable());
    }

    public function test_the_recipient_check_matches_the_wordpress_rule(): void
    {
        $service = $this->app->make(EmailServiceInterface::class);

        $this->assertTrue($service->validateRecipient('someone@example.com'));
        $this->assertFalse($service->validateRecipient('not-an-address'));
        $this->assertFalse($service->validateRecipient(''));
    }

    public function test_the_tool_sends_one_message_per_valid_recipient(): void
    {
        Mail::fake();

        $result = $this->app->make(SendGroupEmailTool::class)->execute(array(
            'to' => array('one@example.com', 'two@example.com'),
            'subject' => 'Quarterly update',
            'body' => "Line one\nLine two",
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['sent']);
        $this->assertSame(0, $result['data']['failed']);

        Mail::assertSent(OutboundMessage::class, 2);
        Mail::assertSent(
            OutboundMessage::class,
            static fn (OutboundMessage $mail): bool => 'Quarterly update' === $mail->subjectLine
        );
    }

    public function test_an_invalid_address_is_reported_and_not_attempted(): void
    {
        Mail::fake();

        $result = $this->app->make(SendGroupEmailTool::class)->execute(array(
            'to' => array('good@example.com', 'nope'),
            'subject' => 'Hi',
            'body' => 'Body',
        ));

        $this->assertSame(1, $result['data']['sent']);
        $this->assertSame(1, $result['data']['failed']);
        $this->assertSame(array('nope'), $result['data']['failed_recipients']);

        Mail::assertSent(OutboundMessage::class, 1);
    }

    public function test_the_html_flag_survives_but_the_tool_sanitiser_strips_markup(): void
    {
        Mail::fake();

        $this->app->make(SendGroupEmailTool::class)->execute(array(
            'to' => array('one@example.com'),
            'subject' => 'Formatted',
            'body' => '<p>Hello</p>',
            'is_html' => true,
        ));

        // AbstractTool::stringParam() runs trim(strip_tags()) at the entry
        // gate, so is_html reaches the mailer but the body cannot carry markup.
        // Pinned as behaviour, not intent: lifting it means changing the core
        // tool, which the WordPress runtime shares.
        Mail::assertSent(
            OutboundMessage::class,
            static fn (OutboundMessage $mail): bool => $mail->isHtml && 'Hello' === $mail->body
        );
    }

    public function test_the_validated_variant_inherits_the_same_send_path(): void
    {
        Mail::fake();

        $result = $this->app->make(SendGroupEmailValidatedTool::class)->execute(array(
            'to' => array('one@example.com'),
            'subject' => 'Inherited',
            'body' => 'Body',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['sent']);

        Mail::assertSent(OutboundMessage::class, 1);
    }

    public function test_a_missing_recipient_list_is_a_validation_error(): void
    {
        Mail::fake();

        $result = $this->app->make(SendGroupEmailTool::class)->execute(array(
            'subject' => 'No recipients',
            'body' => 'Body',
        ));

        // The Laravel error factory returns a RuntimeException carrying the
        // HTTP status, so a validation failure is an object here rather than
        // the WordPress WP_Error shape.
        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        Mail::assertNothingSent();
    }
}
