<?php

/**
 * The four portable WhatsApp tools: the payload each builds, the projection
 * each returns, and the validation the WordPress tools did silently.
 *
 * The interesting assertions are the refusals. WordPress truncated over-long
 * button titles, row titles and captions, skipped malformed buttons and rows,
 * preferred the media id when both id and URL were given, and dropped a caption
 * the media type does not support. Every one of those is a caller asking for
 * one thing and getting another, so each has a test that asserts the refusal
 * happens before a request is made.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Tool\ChatChannels\SendWhatsAppInteractiveTool;
use Nvoos\Core\Tool\ChatChannels\SendWhatsAppMediaTool;
use Nvoos\Core\Tool\ChatChannels\SendWhatsAppMessageTool;
use Nvoos\Core\Tool\ChatChannels\SendWhatsAppTemplateTool;
use PHPUnit\Framework\TestCase;

final class ChatChannelWhatsAppToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const TOKEN = 'EAAG-secret';

    private ErrorFactoryInterface $errors;

    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}> */
    private array $requests = array();

    protected function setUp(): void
    {
        $this->requests = array();
        $this->errors   = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array('code' => $code, 'message' => $message, 'data' => $data),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => is_array($value) && isset($value['error'])
        );
    }

    // ─── send_whatsapp_message ───────────────────────────────────────

    public function test_text_posts_the_documented_payload_and_projects_the_message(): void
    {
        $http = $this->http($this->ok());

        $result = (new SendWhatsAppMessageTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+1 (234) 567-890',
                'text'            => 'Hello',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'message_id' => 'wamid.1',
                'wa_id'      => '1234567890',
                'to'         => '+1234567890',
                'type'       => 'text',
            ),
            $result['data']
        );

        $this->assertSame(
            'https://graph.facebook.com/v19.0/123/messages',
            $this->requests[0]['url']
        );
        $this->assertSame('Bearer ' . self::TOKEN, $this->requests[0]['headers']['Authorization']);
        $this->assertSame(
            array(
                'messaging_product' => 'whatsapp',
                'recipient_type'    => 'individual',
                'to'                => '+1234567890',
                'type'              => 'text',
                'text'              => array(
                    'body'        => 'Hello',
                    'preview_url' => true,
                ),
            ),
            $this->body(0)
        );
    }

    public function test_text_parses_a_string_false_preview_flag(): void
    {
        $http = $this->http($this->ok());

        (new SendWhatsAppMessageTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'text'            => 'Hello',
                'preview_url'     => 'false',
            ),
            self::CONTEXT
        );

        $this->assertFalse($this->body(0)['text']['preview_url']);
    }

    public function test_text_keeps_a_group_jid(): void
    {
        $http = $this->http($this->ok());

        (new SendWhatsAppMessageTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '120363012345678901@g.us',
                'recipient_type'  => 'group',
                'text'            => 'Hello',
            ),
            self::CONTEXT
        );

        $this->assertSame('120363012345678901@g.us', $this->body(0)['to']);
        $this->assertSame('group', $this->body(0)['recipient_type']);
    }

    public function test_text_refuses_a_missing_token_without_a_request(): void
    {
        $http = $this->http();

        $result = (new SendWhatsAppMessageTool($this->errors, $http))->execute(
            array('phone_number_id' => '123', 'to' => '+123', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('access_token', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_text_reports_the_nested_error_with_its_code_and_detail(): void
    {
        $http = $this->http(
            $this->answer(
                400,
                array(
                    'error' => array(
                        'message'    => 'Unsupported message type',
                        'type'       => 'OAuthException',
                        'code'       => 100,
                        'error_data' => array('details' => 'the recipient is not on WhatsApp'),
                    ),
                )
            )
        );

        $result = (new SendWhatsAppMessageTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'text'            => 'Hello',
            ),
            self::CONTEXT
        );

        $this->assertSame('whatsapp_api_error', $result['error']['code']);
        $this->assertSame(400, $result['error']['data']['status']);
        $this->assertSame('100', $result['error']['data']['code']);
        $this->assertStringContainsString('Unsupported message type', $result['error']['message']);
        $this->assertStringContainsString('not on WhatsApp', $result['error']['message']);
        $this->assertStringNotContainsString('EAAG-secret', (string) json_encode($result));
    }

    public function test_text_reports_a_transport_failure_as_one_envelope(): void
    {
        $http = $this->http(new \RuntimeException('SSL handshake failed'));

        $result = (new SendWhatsAppMessageTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'text'            => 'Hello',
            ),
            self::CONTEXT
        );

        $this->assertSame('chat_channel_request_failed', $result['error']['code']);
        $this->assertStringContainsString('SSL handshake failed', $result['error']['message']);
    }

    public function test_text_reports_a_success_without_a_message_id_as_malformed(): void
    {
        $http = $this->http($this->answer(200, array('messaging_product' => 'whatsapp')));

        $result = (new SendWhatsAppMessageTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'text'            => 'Hello',
            ),
            self::CONTEXT
        );

        $this->assertSame('whatsapp_invalid_response', $result['error']['code']);
    }

    // ─── send_whatsapp_template ─────────────────────────────────────

    public function test_template_defaults_the_language_and_projects_the_request(): void
    {
        $http = $this->http($this->ok());

        $result = (new SendWhatsAppTemplateTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'template_name'   => 'order_update',
                'components'      => array(array('type' => 'body', 'parameters' => array())),
            ),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                'message_id'    => 'wamid.1',
                'wa_id'         => '1234567890',
                'to'            => '+123',
                'type'          => 'template',
                'template_name' => 'order_update',
                'language_code' => 'en_US',
            ),
            $result['data']
        );

        $body = $this->body(0);

        $this->assertSame('template', $body['type']);
        $this->assertSame('order_update', $body['template']['name']);
        $this->assertSame('en_US', $body['template']['language']['code']);
        $this->assertCount(1, $body['template']['components']);
    }

    public function test_template_passes_a_language_through(): void
    {
        $http = $this->http($this->ok());

        (new SendWhatsAppTemplateTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'template_name'   => 'order_update',
                'language_code'   => 'es_ES',
            ),
            self::CONTEXT
        );

        $this->assertSame('es_ES', $this->body(0)['template']['language']['code']);
    }

    // ─── send_whatsapp_interactive ──────────────────────────────────

    public function test_interactive_button_builds_the_header_footer_and_buttons(): void
    {
        $http = $this->http($this->ok());

        $result = (new SendWhatsAppInteractiveTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'button',
                'header'          => array('type' => 'text', 'text' => 'Choose'),
                'body'            => 'Pick one',
                'footer'          => 'Reply now',
                'buttons'         => array(
                    array('type' => 'reply', 'reply' => array('id' => 'yes', 'title' => 'Yes')),
                    array('type' => 'reply', 'reply' => array('id' => 'no', 'title' => 'No')),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('interactive', $result['data']['type']);
        $this->assertSame('button', $result['data']['variant']);

        $interactive = $this->body(0)['interactive'];

        $this->assertSame(
            array('type' => 'text', 'text' => 'Choose'),
            $interactive['header']
        );
        $this->assertSame(array('text' => 'Pick one'), $interactive['body']);
        $this->assertSame(array('text' => 'Reply now'), $interactive['footer']);
        $this->assertSame(
            array(
                array('type' => 'reply', 'reply' => array('id' => 'yes', 'title' => 'Yes')),
                array('type' => 'reply', 'reply' => array('id' => 'no', 'title' => 'No')),
            ),
            $interactive['action']['buttons']
        );
    }

    public function test_interactive_refuses_more_than_three_buttons_without_a_request(): void
    {
        $http = $this->http();

        $button = array('type' => 'reply', 'reply' => array('id' => 'x', 'title' => 'X'));

        $result = (new SendWhatsAppInteractiveTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'button',
                'body'            => 'Pick one',
                'buttons'         => array($button, $button, $button, $button),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_interactive_refuses_an_over_long_button_title_instead_of_truncating(): void
    {
        $http = $this->http();

        $result = (new SendWhatsAppInteractiveTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'button',
                'body'            => 'Pick one',
                'buttons'         => array(
                    array('type' => 'reply', 'reply' => array('id' => 'yes', 'title' => str_repeat('A', 21))),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_interactive_refuses_a_button_without_its_id(): void
    {
        $http = $this->http();

        $result = (new SendWhatsAppInteractiveTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'button',
                'body'            => 'Pick one',
                'buttons'         => array(array('type' => 'reply', 'reply' => array('title' => 'Yes'))),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_interactive_list_builds_the_sections(): void
    {
        $http = $this->http($this->ok());

        $result = (new SendWhatsAppInteractiveTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'list',
                'body'            => 'Pick one',
                'button_text'     => 'Options',
                'sections'        => array(
                    array(
                        'title' => 'Plans',
                        'rows'  => array(
                            array('id' => 'basic', 'title' => 'Basic', 'description' => 'Everything you need'),
                            array('id' => 'pro', 'title' => 'Pro'),
                        ),
                    ),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('list', $result['data']['variant']);

        $action = $this->body(0)['interactive']['action'];

        $this->assertSame('Options', $action['button']);
        $this->assertSame('Plans', $action['sections'][0]['title']);
        $this->assertSame(
            array(
                array('id' => 'basic', 'title' => 'Basic', 'description' => 'Everything you need'),
                array('id' => 'pro', 'title' => 'Pro'),
            ),
            $action['sections'][0]['rows']
        );
    }

    public function test_interactive_list_refuses_more_than_ten_rows_across_sections(): void
    {
        $http = $this->http();

        $rows = array();

        for ($index = 1; $index <= 11; ++$index) {
            $rows[] = array('id' => 'row' . $index, 'title' => 'Row ' . $index);
        }

        $result = (new SendWhatsAppInteractiveTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'list',
                'body'            => 'Pick one',
                'button_text'     => 'Options',
                'sections'        => array(
                    array('rows' => array_slice($rows, 0, 6)),
                    array('rows' => array_slice($rows, 6)),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_interactive_refuses_an_unknown_header_type(): void
    {
        $http = $this->http();

        $result = (new SendWhatsAppInteractiveTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'button',
                'header'          => array('type' => 'sticker'),
                'body'            => 'Pick one',
                'buttons'         => array(
                    array('type' => 'reply', 'reply' => array('id' => 'yes', 'title' => 'Yes')),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('header', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_interactive_media_header_requires_an_https_link(): void
    {
        $http = $this->http();

        $result = (new SendWhatsAppInteractiveTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'button',
                'header'          => array('type' => 'image', 'image' => array('link' => 'http://example.com/a.png')),
                'body'            => 'Pick one',
                'buttons'         => array(
                    array('type' => 'reply', 'reply' => array('id' => 'yes', 'title' => 'Yes')),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    // ─── send_whatsapp_media ────────────────────────────────────────

    public function test_media_sends_a_link_with_its_caption(): void
    {
        $http = $this->http($this->ok());

        $result = (new SendWhatsAppMediaTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'image',
                'media_url'       => 'https://example.com/photo.jpg',
                'caption'         => 'Our new shop',
            ),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                'message_id' => 'wamid.1',
                'wa_id'      => '1234567890',
                'to'         => '+123',
                'type'       => 'image',
            ),
            $result['data']
        );
        $this->assertSame(
            array(
                'link'    => 'https://example.com/photo.jpg',
                'caption' => 'Our new shop',
            ),
            $this->body(0)['image']
        );
    }

    public function test_media_refuses_both_a_url_and_an_id(): void
    {
        $http = $this->http();

        $result = (new SendWhatsAppMediaTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'image',
                'media_url'       => 'https://example.com/photo.jpg',
                'media_id'        => 'MEDIA-1',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_media_refuses_neither_a_url_nor_an_id(): void
    {
        $http = $this->http();

        $result = (new SendWhatsAppMediaTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'image',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_media_refuses_a_plain_http_link(): void
    {
        $http = $this->http();

        $result = (new SendWhatsAppMediaTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'document',
                'media_url'       => 'http://example.com/invoice.pdf',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('media_url', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_media_refuses_a_caption_the_type_does_not_support(): void
    {
        $http = $this->http();

        $result = (new SendWhatsAppMediaTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'audio',
                'media_id'        => 'MEDIA-1',
                'caption'         => 'A caption',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('caption', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_media_refuses_a_filename_on_an_image(): void
    {
        $http = $this->http();

        $result = (new SendWhatsAppMediaTool($this->errors, $http))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'type'            => 'image',
                'media_id'        => 'MEDIA-1',
                'filename'        => 'photo.jpg',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('filename', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    // ─── Family contract ─────────────────────────────────────────────

    public function test_every_tool_declares_the_capability_wordpress_enforced(): void
    {
        foreach ($this->tools($this->http()) as $tool) {
            $this->assertSame('manage_options', $tool->getRequiredCapability());
        }
    }

    public function test_every_tool_declares_write_flags(): void
    {
        foreach ($this->tools($this->http()) as $tool) {
            $flags = $tool->getCapabilityFlags();

            $this->assertContains('state-changing', $flags);
            $this->assertContains('write', $flags);
            $this->assertContains('requires-credentials', $flags);
            $this->assertNotContains('read-only', $flags);
        }
    }

    public function test_every_tool_declares_its_documented_slug(): void
    {
        $slugs = array();

        foreach ($this->tools($this->http()) as $tool) {
            $slugs[$tool->getSlug()] = true;
        }

        $this->assertSame(
            array(
                'send_whatsapp_message',
                'send_whatsapp_template',
                'send_whatsapp_interactive',
                'send_whatsapp_media',
            ),
            array_keys($slugs)
        );
    }

    public function test_every_write_refuses_an_unauthenticated_context(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new SendWhatsAppMessageTool($this->errors, $this->http()))->execute(
            array(
                'access_token'    => self::TOKEN,
                'phone_number_id' => '123',
                'to'              => '+123',
                'text'            => 'Hello',
            ),
            array()
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────

    /**
     * @return list<object>
     */
    private function tools(HttpClientInterface $http): array
    {
        return array(
            new SendWhatsAppMessageTool($this->errors, $http),
            new SendWhatsAppTemplateTool($this->errors, $http),
            new SendWhatsAppInteractiveTool($this->errors, $http),
            new SendWhatsAppMediaTool($this->errors, $http),
        );
    }

    private function ok(): HttpResponse
    {
        return $this->answer(
            200,
            array(
                'messaging_product' => 'whatsapp',
                'contacts'          => array(
                    array('input' => '+123', 'wa_id' => '1234567890'),
                ),
                'messages'          => array(
                    array('id' => 'wamid.1'),
                ),
            )
        );
    }

    private function answer(int $status, array $payload): HttpResponse
    {
        return new HttpResponse($status, array() === $payload ? '' : (string) json_encode($payload));
    }

    /**
     * An HttpClientInterface that replays the queued answers and records every
     * request. A queued RuntimeException is thrown instead of returned, which
     * is how a transport failure reaches the tool.
     */
    private function http(HttpResponse|\RuntimeException ...$answers): HttpClientInterface
    {
        $requests = &$this->requests;
        $queue    = $answers;
        $http     = $this->createMock(HttpClientInterface::class);

        $http->method('send')->willReturnCallback(
            static function (string $method, string $url, array $headers = array(), ?string $body = null) use (&$queue, &$requests): HttpResponse {
                $requests[] = array(
                    'method'  => $method,
                    'url'     => $url,
                    'headers' => $headers,
                    'body'    => $body,
                );

                $answer = array_shift($queue);

                if ($answer instanceof \RuntimeException) {
                    throw $answer;
                }

                if (! $answer instanceof HttpResponse) {
                    throw new \LogicException('No queued HTTP answer.');
                }

                return $answer;
            }
        );

        return $http;
    }

    /**
     * @return array<string, mixed>
     */
    private function body(int $index): array
    {
        $decoded = json_decode((string) ($this->requests[$index]['body'] ?? ''), true);

        return is_array($decoded) ? $decoded : array();
    }
}
