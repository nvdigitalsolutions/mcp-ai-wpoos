<?php

/**
 * The five Telegram tools: the request each makes, the projection each
 * returns, the chunker, and the ways a Bot API call fails.
 *
 * Four things are worth pinning. First, the token is a path segment: it is
 * encoded once, and a failure must never echo it. Second, Telegram reports
 * refusals with `ok: false` plus `error_code` and `description`, and a 429
 * carries `parameters.retry_after`. Third, a long message is split and a
 * partial send must name what was delivered. Fourth, the commands tool must
 * refuse a scope whose id it does not have, and must not silently rewrite a
 * command name it cannot honour.
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
use Nvoos\Core\Tool\ChatChannels\AddTelegramMessageReactionTool;
use Nvoos\Core\Tool\ChatChannels\GetTelegramUpdatesTool;
use Nvoos\Core\Tool\ChatChannels\ManageTelegramCommandsTool;
use Nvoos\Core\Tool\ChatChannels\ManageTelegramWebhookTool;
use Nvoos\Core\Tool\ChatChannels\SendTelegramMessageTool;
use PHPUnit\Framework\TestCase;

final class ChatChannelTelegramToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const TOKEN = '123456:ABC-def_ghi';

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

    // ─── send_telegram_message ───────────────────────────────────────

    public function test_send_posts_to_the_encoded_token_route_and_projects_the_message(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'ok'     => true,
                    'result' => array(
                        'message_id' => 41,
                        'chat'       => array('id' => -100123, 'title' => 'not in the contract'),
                        'date'       => 1730000000,
                        'text'       => 'Hello',
                        'entities'   => array('not in the contract'),
                    ),
                )
            )
        );

        $result = (new SendTelegramMessageTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'chat_id' => '@probe', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'message_id'  => 41,
                'chat_id'     => '-100123',
                'date'        => 1730000000,
                'text'        => 'Hello',
                'chunks_sent' => 1,
            ),
            $result['data']
        );
        $this->assertSame(
            'https://api.telegram.org/bot123456%3AABC-def_ghi/sendMessage',
            $this->requests[0]['url']
        );
        $this->assertSame(array('chat_id' => '@probe', 'text' => 'Hello'), $this->body(0));
    }

    public function test_send_includes_the_parse_mode_and_preview_flag_when_asked(): void
    {
        $http = $this->http($this->sent(1));

        (new SendTelegramMessageTool($this->errors, $http))->execute(
            array(
                'token'                    => self::TOKEN,
                'chat_id'                  => '1',
                'text'                     => '<b>Hello</b>',
                'parse_mode'               => 'HTML',
                'disable_web_page_preview' => true,
            ),
            self::CONTEXT
        );

        $body = $this->body(0);

        $this->assertSame('HTML', $body['parse_mode']);
        $this->assertTrue($body['disable_web_page_preview']);
    }

    public function test_send_refuses_an_unsupported_parse_mode_without_a_request(): void
    {
        $http = $this->http();

        $result = (new SendTelegramMessageTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'chat_id' => '1', 'text' => 'Hello', 'parse_mode' => 'markdown'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('parse_mode', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_send_refuses_a_missing_token_without_a_request(): void
    {
        $http = $this->http();

        $result = (new SendTelegramMessageTool($this->errors, $http))->execute(
            array('chat_id' => '1', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('token', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_send_splits_at_paragraph_boundaries_before_the_limit(): void
    {
        $paragraph = static fn (string $letter): string => str_repeat($letter, 2000);
        $text      = $paragraph('B') . "\n\n" . $paragraph('C') . "\n\n" . $paragraph('D');

        $http = $this->http($this->sent(1), $this->sent(2));

        $result = (new SendTelegramMessageTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'chat_id' => '1', 'text' => $text),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['chunks_sent']);
        $this->assertCount(2, $this->requests);

        foreach (array(0, 1) as $index) {
            $body = $this->body($index);

            $this->assertLessThanOrEqual(4096, \mb_strlen((string) $body['text']));
            $this->assertArrayNotHasKey('parse_mode', $body);
        }

        $this->assertStringStartsWith('BBB', (string) $this->body(0)['text']);
        $this->assertStringStartsWith('DDD', (string) $this->body(1)['text']);
    }

    public function test_send_hard_splits_an_unbroken_block_and_drops_the_parse_mode(): void
    {
        $http = $this->http($this->sent(1), $this->sent(2));

        $result = (new SendTelegramMessageTool($this->errors, $http))->execute(
            array(
                'token'      => self::TOKEN,
                'chat_id'    => '1',
                'text'       => str_repeat('A', 4100),
                'parse_mode' => 'HTML',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['chunks_sent']);
        $this->assertSame(4096, \mb_strlen((string) $this->body(0)['text']));
        $this->assertSame(4, \mb_strlen((string) $this->body(1)['text']));
        $this->assertArrayNotHasKey('parse_mode', $this->body(0));
    }

    public function test_send_reports_a_refusal_with_telegrams_own_code(): void
    {
        $http = $this->http(
            $this->answer(
                400,
                array(
                    'ok'          => false,
                    'error_code'  => 400,
                    'description' => 'Bad Request: chat not found',
                )
            )
        );

        $result = (new SendTelegramMessageTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'chat_id' => '1', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('telegram_api_error', $result['error']['code']);
        $this->assertSame(400, $result['error']['data']['status']);
        $this->assertSame('400', $result['error']['data']['code']);
        $this->assertStringContainsString('chat not found', $result['error']['message']);
        $this->assertStringNotContainsString('ABC-def_ghi', (string) json_encode($result));
    }

    public function test_send_surfaces_the_retry_interval_from_a_429(): void
    {
        $http = $this->http(
            $this->answer(
                429,
                array(
                    'ok'          => false,
                    'error_code'  => 429,
                    'description' => 'Too Many Requests: retry after 3',
                    'parameters'  => array('retry_after' => 3),
                )
            )
        );

        $result = (new SendTelegramMessageTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'chat_id' => '1', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame(429, $result['error']['data']['status']);
        $this->assertSame(3, $result['error']['data']['retry_after']);
    }

    public function test_send_reports_a_transport_failure_as_one_envelope(): void
    {
        $http = $this->http(new \RuntimeException('Could not resolve host'));

        $result = (new SendTelegramMessageTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'chat_id' => '1', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('chat_channel_request_failed', $result['error']['code']);
        $this->assertStringContainsString('Could not resolve host', $result['error']['message']);
    }

    public function test_send_reports_a_partial_chunk_failure_with_what_was_delivered(): void
    {
        $http = $this->http(
            $this->sent(1),
            $this->answer(
                400,
                array(
                    'ok'          => false,
                    'error_code'  => 400,
                    'description' => 'Bad Request: message is too long',
                )
            )
        );

        $result = (new SendTelegramMessageTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'chat_id' => '1', 'text' => str_repeat('A', 4100)),
            self::CONTEXT
        );

        $this->assertSame('telegram_chunk_error', $result['error']['code']);
        $this->assertSame(2, $result['error']['data']['chunk']);
        $this->assertSame(2, $result['error']['data']['total_chunks']);
        $this->assertSame('400', $result['error']['data']['code']);
        $this->assertCount(1, $result['error']['data']['sent']);
        $this->assertSame(1, $result['error']['data']['sent'][0]['message_id']);
    }

    // ─── get_telegram_updates ────────────────────────────────────────

    public function test_updates_flattens_a_message_and_a_callback_query(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'ok'     => true,
                    'result' => array(
                        array(
                            'update_id' => 100,
                            'message'   => array(
                                'message_id' => 7,
                                'date'       => 1730000000,
                                'text'       => 'Hi',
                                'chat'       => array('id' => 55),
                                'from'       => array('id' => 66, 'username' => 'alice'),
                            ),
                        ),
                        array(
                            'update_id'      => 101,
                            'callback_query' => array(
                                'id'   => 'cb-1',
                                'from' => array('id' => 77, 'username' => 'bob'),
                                'data' => 'pressed',
                                'message' => array(
                                    'message_id' => 8,
                                    'date'       => 1730000100,
                                    'chat'       => array('id' => 55),
                                ),
                            ),
                        ),
                        'not-an-update',
                    ),
                )
            )
        );

        $result = (new GetTelegramUpdatesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                array(
                    'update_id'     => 100,
                    'type'          => 'message',
                    'message_id'    => 7,
                    'chat_id'       => '55',
                    'date'          => 1730000000,
                    'text'          => 'Hi',
                    'from_id'       => '66',
                    'from_username' => 'alice',
                ),
                array(
                    'update_id'     => 101,
                    'type'          => 'callback_query',
                    'message_id'    => 8,
                    'chat_id'       => '55',
                    'date'          => 1730000100,
                    'text'          => 'pressed',
                    'from_id'       => '77',
                    'from_username' => 'bob',
                ),
            ),
            $result['data']['updates']
        );
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(102, $result['data']['next_offset']);
    }

    public function test_updates_sends_the_offset_and_a_clamped_limit(): void
    {
        $http = $this->http($this->answer(200, array('ok' => true, 'result' => array())));

        $result = (new GetTelegramUpdatesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'offset' => 42, 'limit' => 5000),
            self::CONTEXT
        );

        $query = $this->query(0);

        $this->assertSame('42', $query['offset']);
        $this->assertSame('100', $query['limit']);
        $this->assertNull($result['data']['next_offset']);
    }

    public function test_updates_reports_the_webhook_conflict(): void
    {
        $http = $this->http(
            $this->answer(
                409,
                array(
                    'ok'          => false,
                    'error_code'  => 409,
                    'description' => 'Conflict: can not use getUpdates method while webhook is active',
                )
            )
        );

        $result = (new GetTelegramUpdatesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN),
            self::CONTEXT
        );

        $this->assertSame('telegram_api_error', $result['error']['code']);
        $this->assertSame(409, $result['error']['data']['status']);
        $this->assertStringContainsString('webhook is active', $result['error']['message']);
    }

    // ─── add_telegram_message_reaction ───────────────────────────────

    public function test_reaction_posts_the_reaction_shape_and_projects_the_target(): void
    {
        $http = $this->http($this->answer(200, array('ok' => true, 'result' => true)));

        $result = (new AddTelegramMessageReactionTool($this->errors, $http))->execute(
            array(
                'token'      => self::TOKEN,
                'chat_id'    => 55,
                'message_id' => 7,
                'emoji'      => '👍',
                'is_big'     => true,
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'chat_id'    => '55',
                'message_id' => 7,
                'emoji'      => '👍',
                'is_big'     => true,
            ),
            $result['data']
        );
        $this->assertSame(
            array(
                'chat_id'    => '55',
                'message_id' => 7,
                'reaction'   => array(
                    array('type' => 'emoji', 'emoji' => '👍'),
                ),
                'is_big'     => true,
            ),
            $this->body(0)
        );
        $this->assertStringEndsWith('/setMessageReaction', $this->requests[0]['url']);
    }

    public function test_reaction_refuses_a_non_positive_message_id(): void
    {
        $http = $this->http();

        $result = (new AddTelegramMessageReactionTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'chat_id' => '55', 'message_id' => 0, 'emoji' => '👍'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('message_id', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    // ─── manage_telegram_commands ────────────────────────────────────

    public function test_commands_set_lowercases_and_posts_the_scope(): void
    {
        $http = $this->http($this->answer(200, array('ok' => true, 'result' => true)));

        $result = (new ManageTelegramCommandsTool($this->errors, $http))->execute(
            array(
                'token'   => self::TOKEN,
                'action'  => 'set',
                'scope'   => 'chat',
                'chat_id' => '55',
                'commands' => array(
                    array('command' => 'Status', 'description' => 'Show status'),
                    array('command' => 'run_task', 'description' => 'Run a task'),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('set', $result['data']['action']);
        $this->assertSame('chat', $result['data']['scope']);
        $this->assertSame('55', $result['data']['chat_id']);
        $this->assertSame(2, $result['data']['command_count']);

        $body = $this->body(0);

        $this->assertSame(
            array('type' => 'chat', 'chat_id' => '55'),
            $body['scope']
        );
        $this->assertSame(
            array(
                array('command' => 'status', 'description' => 'Show status'),
                array('command' => 'run_task', 'description' => 'Run a task'),
            ),
            $body['commands']
        );
        $this->assertStringEndsWith('/setMyCommands', $this->requests[0]['url']);
    }

    public function test_commands_set_refuses_a_name_it_cannot_register_without_a_request(): void
    {
        $http = $this->http();

        $result = (new ManageTelegramCommandsTool($this->errors, $http))->execute(
            array(
                'token'    => self::TOKEN,
                'action'   => 'set',
                'commands' => array(array('command' => 'hello-world', 'description' => 'Hyphenated')),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_commands_refuses_a_chat_scope_without_the_chat(): void
    {
        $http = $this->http();

        $result = (new ManageTelegramCommandsTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'action' => 'get', 'scope' => 'chat'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('chat_id', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_commands_get_projects_the_list(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'ok'     => true,
                    'result' => array(
                        array('command' => 'status', 'description' => 'Show status'),
                        'not-a-command',
                    ),
                )
            )
        );

        $result = (new ManageTelegramCommandsTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'action' => 'get'),
            self::CONTEXT
        );

        $this->assertSame(
            array(array('command' => 'status', 'description' => 'Show status')),
            $result['data']['commands']
        );
        $this->assertSame(1, $result['data']['command_count']);
        $this->assertStringEndsWith('/getMyCommands', $this->requests[0]['url']);
    }

    public function test_commands_delete_sends_no_body_for_the_default_scope(): void
    {
        $http = $this->http($this->answer(200, array('ok' => true, 'result' => true)));

        $result = (new ManageTelegramCommandsTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'action' => 'delete'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['deleted']);
        $this->assertNull($this->requests[0]['body']);
        $this->assertStringEndsWith('/deleteMyCommands', $this->requests[0]['url']);
    }

    // ─── manage_telegram_webhook ─────────────────────────────────────

    public function test_webhook_set_posts_the_url_the_connection_cap_and_the_secret(): void
    {
        $http = $this->http(
            $this->answer(200, array('ok' => true, 'result' => true, 'description' => 'Webhook was set'))
        );

        $result = (new ManageTelegramWebhookTool($this->errors, $http))->execute(
            array(
                'token'           => self::TOKEN,
                'action'          => 'set',
                'url'             => 'https://example.com/telegram',
                'max_connections' => 200,
                'secret_token'    => 'abc_DEF-123',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('https://example.com/telegram', $result['data']['url']);
        $this->assertSame(100, $result['data']['max_connections']);
        $this->assertTrue($result['data']['secret_token_set']);

        $body = $this->body(0);

        $this->assertSame('https://example.com/telegram', $body['url']);
        $this->assertSame(100, $body['max_connections']);
        $this->assertSame('abc_DEF-123', $body['secret_token']);
        $this->assertContains('chat_member', $body['allowed_updates']);
        $this->assertStringEndsWith('/setWebhook', $this->requests[0]['url']);
    }

    public function test_webhook_refuses_a_non_https_url_without_a_request(): void
    {
        $http = $this->http();

        $result = (new ManageTelegramWebhookTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'action' => 'set', 'url' => 'http://example.com/telegram'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('url', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_webhook_refuses_an_invalid_secret_without_a_request(): void
    {
        $http = $this->http();

        $result = (new ManageTelegramWebhookTool($this->errors, $http))->execute(
            array(
                'token'        => self::TOKEN,
                'action'       => 'set',
                'url'          => 'https://example.com/telegram',
                'secret_token' => 'not valid!',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('secret_token', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_webhook_delete_sends_no_body(): void
    {
        $http = $this->http(
            $this->answer(200, array('ok' => true, 'result' => true, 'description' => 'Webhook was deleted'))
        );

        $result = (new ManageTelegramWebhookTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'action' => 'delete'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('delete', $result['data']['action']);
        $this->assertNull($this->requests[0]['body']);
        $this->assertStringEndsWith('/deleteWebhook', $this->requests[0]['url']);
    }

    // ─── Family contract ─────────────────────────────────────────────

    public function test_every_tool_declares_the_capability_wordpress_enforced(): void
    {
        foreach ($this->tools($this->http()) as $tool) {
            $this->assertSame('manage_options', $tool->getRequiredCapability());
        }
    }

    public function test_reads_declare_read_only_and_writes_declare_write(): void
    {
        $http = $this->http();

        $read = (new GetTelegramUpdatesTool($this->errors, $http))->getCapabilityFlags();

        $this->assertContains('read-only', $read);
        $this->assertNotContains('write', $read);

        $writers = array(
            new SendTelegramMessageTool($this->errors, $http),
            new AddTelegramMessageReactionTool($this->errors, $http),
            new ManageTelegramCommandsTool($this->errors, $http),
            new ManageTelegramWebhookTool($this->errors, $http),
        );

        foreach ($writers as $tool) {
            $flags = $tool->getCapabilityFlags();

            $this->assertContains('state-changing', $flags);
            $this->assertContains('write', $flags);
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
                'send_telegram_message',
                'get_telegram_updates',
                'add_telegram_message_reaction',
                'manage_telegram_commands',
                'manage_telegram_webhook',
            ),
            array_keys($slugs)
        );
    }

    public function test_every_write_refuses_an_unauthenticated_context(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new SendTelegramMessageTool($this->errors, $this->http()))->execute(
            array('token' => self::TOKEN, 'chat_id' => '1', 'text' => 'Hello'),
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
            new SendTelegramMessageTool($this->errors, $http),
            new GetTelegramUpdatesTool($this->errors, $http),
            new AddTelegramMessageReactionTool($this->errors, $http),
            new ManageTelegramCommandsTool($this->errors, $http),
            new ManageTelegramWebhookTool($this->errors, $http),
        );
    }

    private function sent(int $id): HttpResponse
    {
        return $this->answer(
            200,
            array(
                'ok'     => true,
                'result' => array(
                    'message_id' => $id,
                    'chat'       => array('id' => 1),
                    'date'       => 1730000000,
                    'text'       => 'part ' . $id,
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

    /**
     * @return array<string, string>
     */
    private function query(int $index): array
    {
        $query = parse_url($this->requests[$index]['url'], PHP_URL_QUERY);

        if (! is_string($query)) {
            return array();
        }

        parse_str($query, $parsed);

        $result = array();

        foreach ($parsed as $key => $value) {
            if (is_scalar($value)) {
                $result[(string) $key] = (string) $value;
            }
        }

        return $result;
    }
}
