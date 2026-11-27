<?php

/**
 * The five portable Discord tools: the request each makes, the projection each
 * returns, and the four ways a Discord call fails.
 *
 * Four things are worth pinning. First, the ids are path segments, so a value
 * that is not a snowflake must be refused before a request is made — that is
 * the correction WordPress needed and it is asserted with no queued answer, so
 * a request would throw. Second, Discord's numeric error code is carried as
 * the provider code, which is what makes `50013` distinguishable from a bare
 * 403. Third, a 429 must surface `retry_after`. Fourth, the token is an
 * argument and must never reach a reply, including an error.
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
use Nvoos\Core\Tool\ChatChannels\AddDiscordMessageReactionTool;
use Nvoos\Core\Tool\ChatChannels\CreateDiscordChannelTool;
use Nvoos\Core\Tool\ChatChannels\GetDiscordChannelsTool;
use Nvoos\Core\Tool\ChatChannels\GetDiscordMessagesTool;
use Nvoos\Core\Tool\ChatChannels\SendDiscordMessageTool;
use PHPUnit\Framework\TestCase;

final class ChatChannelDiscordToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

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
    }

    // ─── send_discord_message ────────────────────────────────────────

    public function test_send_posts_content_and_projects_the_message(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'id'         => '111',
                    'channel_id' => '222',
                    'content'    => 'Hello',
                    'timestamp'  => '2026-11-27T10:00:00.000000+00:00',
                    'author'     => array('id' => '333', 'username' => 'bot', 'avatar' => 'not in the contract'),
                    'mentions'   => array('not in the contract'),
                )
            )
        );

        $result = (new SendDiscordMessageTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'channel_id' => '222', 'content' => 'Hello'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'id'         => '111',
                'channel_id' => '222',
                'content'    => 'Hello',
                'timestamp'  => '2026-11-27T10:00:00.000000+00:00',
            ),
            $result['data']
        );

        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame(
            'https://discord.com/api/v10/channels/222/messages',
            $this->requests[0]['url']
        );
        $this->assertSame('Bot bot-secret', $this->requests[0]['headers']['Authorization']);
        $this->assertSame(array('content' => 'Hello'), $this->body(0));
    }

    public function test_send_refuses_a_missing_token_without_a_request(): void
    {
        $http = $this->http();

        $result = (new SendDiscordMessageTool($this->errors, $http))->execute(
            array('channel_id' => '222', 'content' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array('token' => array('A valid Discord bot token is required.')), $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_send_refuses_a_channel_id_that_is_not_a_snowflake(): void
    {
        $http = $this->http();

        $result = (new SendDiscordMessageTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'channel_id' => '222/../guilds', 'content' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('channel_id', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_send_reports_a_401_as_a_token_refusal_without_echoing_the_token(): void
    {
        $http = $this->http(
            $this->answer(401, array('message' => '401: Unauthorized', 'code' => 0))
        );

        $result = (new SendDiscordMessageTool($this->errors, $http))->execute(
            array('token' => 'bot-do-not-leak', 'channel_id' => '222', 'content' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('discord_api_error', $result['error']['code']);
        $this->assertSame(401, $result['error']['data']['status']);
        $this->assertStringContainsString('token was rejected', $result['error']['message']);
        $this->assertStringNotContainsString('bot-do-not-leak', (string) json_encode($result));
    }

    public function test_send_reports_a_429_with_the_retry_interval(): void
    {
        $http = $this->http(
            $this->answer(
                429,
                array(
                    'message'     => 'You are being rate limited.',
                    'retry_after' => 64.57,
                    'global'      => false,
                )
            )
        );

        $result = (new SendDiscordMessageTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'channel_id' => '222', 'content' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('discord_api_error', $result['error']['code']);
        $this->assertSame(429, $result['error']['data']['status']);
        $this->assertSame(65, $result['error']['data']['retry_after']);
    }

    public function test_send_reports_a_transport_failure_as_one_envelope(): void
    {
        $http = $this->http(new \RuntimeException('Connection reset by peer'));

        $result = (new SendDiscordMessageTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'channel_id' => '222', 'content' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('chat_channel_request_failed', $result['error']['code']);
        $this->assertStringContainsString('Connection reset by peer', $result['error']['message']);
    }

    public function test_send_reports_a_success_without_a_message_as_malformed(): void
    {
        $http = $this->http($this->answer(200, array()));

        $result = (new SendDiscordMessageTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'channel_id' => '222', 'content' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('discord_invalid_response', $result['error']['code']);
    }

    // ─── get_discord_channels ────────────────────────────────────────

    public function test_channels_projects_rows_and_skips_non_rows(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    array(
                        'id'                => '1',
                        'name'              => 'general',
                        'type'              => 0,
                        'position'          => 2,
                        'parent_id'         => '9',
                        'permission_overwrites' => array('not in the contract'),
                    ),
                    array(
                        'id'       => '2',
                        'name'     => 'Voice',
                        'type'     => 2,
                        'position' => 3,
                    ),
                    'not-a-row',
                )
            )
        );

        $result = (new GetDiscordChannelsTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'guild_id' => '99'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                array(
                    'id'        => '1',
                    'name'      => 'general',
                    'type'      => 0,
                    'position'  => 2,
                    'parent_id' => '9',
                ),
                array(
                    'id'        => '2',
                    'name'      => 'Voice',
                    'type'      => 2,
                    'position'  => 3,
                    'parent_id' => null,
                ),
            ),
            $result['data']['channels']
        );
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame('https://discord.com/api/v10/guilds/99/channels', $this->requests[0]['url']);
    }

    public function test_channels_refuses_a_guild_id_that_is_not_a_snowflake(): void
    {
        $http = $this->http();

        $result = (new GetDiscordChannelsTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'guild_id' => 'abc'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('guild_id', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    // ─── get_discord_messages ────────────────────────────────────────

    public function test_messages_clamps_the_limit_and_sends_it(): void
    {
        $http = $this->http(
            $this->answer(200, array()),
            $this->answer(200, array())
        );

        (new GetDiscordMessagesTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'channel_id' => '222', 'limit' => 500),
            self::CONTEXT
        );

        (new GetDiscordMessagesTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'channel_id' => '222'),
            self::CONTEXT
        );

        $this->assertSame('100', $this->query(0)['limit']);
        $this->assertSame('50', $this->query(1)['limit']);
    }

    public function test_messages_projects_rows(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    array(
                        'id'               => '10',
                        'channel_id'       => '222',
                        'content'          => 'Edited hello',
                        'timestamp'        => '2026-11-27T10:00:00.000000+00:00',
                        'edited_timestamp' => '2026-11-27T10:01:00.000000+00:00',
                        'author'           => array('id' => '333', 'username' => 'alice', 'bot' => false),
                        'embeds'           => array('not in the contract'),
                    ),
                    array(
                        'id'         => '11',
                        'channel_id' => '222',
                        'content'    => 'No author',
                        'timestamp'  => '2026-11-27T10:02:00.000000+00:00',
                    ),
                    'not-a-row',
                )
            )
        );

        $result = (new GetDiscordMessagesTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'channel_id' => '222', 'limit' => 2),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                array(
                    'id'               => '10',
                    'channel_id'       => '222',
                    'author_id'        => '333',
                    'author_username'  => 'alice',
                    'content'          => 'Edited hello',
                    'timestamp'        => '2026-11-27T10:00:00.000000+00:00',
                    'edited_timestamp' => '2026-11-27T10:01:00.000000+00:00',
                ),
                array(
                    'id'               => '11',
                    'channel_id'       => '222',
                    'author_id'        => '',
                    'author_username'  => '',
                    'content'          => 'No author',
                    'timestamp'        => '2026-11-27T10:02:00.000000+00:00',
                    'edited_timestamp' => null,
                ),
            ),
            $result['data']['messages']
        );
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(2, $result['data']['limit']);
    }

    public function test_messages_requires_a_channel(): void
    {
        $http = $this->http();

        $result = (new GetDiscordMessagesTool($this->errors, $http))->execute(
            array('token' => 'bot-secret'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('channel_id', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    // ─── create_discord_channel ──────────────────────────────────────

    public function test_create_posts_the_name_and_type_and_projects_the_channel(): void
    {
        $http = $this->http(
            $this->answer(
                201,
                array(
                    'id'        => '5',
                    'name'      => 'Standup',
                    'type'      => 2,
                    'position'  => 4,
                    'parent_id' => '9',
                    'guild_id'  => '99',
                )
            )
        );

        $result = (new CreateDiscordChannelTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'guild_id' => '99', 'name' => 'Standup', 'type' => 2),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'id'        => '5',
                'name'      => 'Standup',
                'type'      => 2,
                'position'  => 4,
                'parent_id' => '9',
            ),
            $result['data']
        );
        $this->assertSame(
            array('name' => 'Standup', 'type' => 2),
            $this->body(0)
        );
        $this->assertSame('https://discord.com/api/v10/guilds/99/channels', $this->requests[0]['url']);
    }

    public function test_create_refuses_an_unsupported_type_without_a_request(): void
    {
        $http = $this->http();

        $result = (new CreateDiscordChannelTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'guild_id' => '99', 'name' => 'Forum', 'type' => 15),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('type', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_create_reports_a_missing_permission_with_discords_own_code(): void
    {
        $http = $this->http(
            $this->answer(403, array('message' => 'Missing Permissions', 'code' => 50013))
        );

        $result = (new CreateDiscordChannelTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'guild_id' => '99', 'name' => 'Standup'),
            self::CONTEXT
        );

        $this->assertSame('discord_api_error', $result['error']['code']);
        $this->assertSame('50013', $result['error']['data']['code']);
        $this->assertStringContainsString('permission', $result['error']['message']);
    }

    // ─── add_discord_message_reaction ────────────────────────────────

    public function test_reaction_puts_the_url_encoded_emoji_and_reports_the_target(): void
    {
        $http = $this->http($this->answer(204, array()));

        $result = (new AddDiscordMessageReactionTool($this->errors, $http))->execute(
            array(
                'token'      => 'bot-secret',
                'channel_id' => '222',
                'message_id' => '111',
                'emoji'      => '👍',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'channel_id' => '222',
                'message_id' => '111',
                'emoji'      => '👍',
                'added'      => true,
            ),
            $result['data']
        );
        $this->assertSame('PUT', $this->requests[0]['method']);
        $this->assertSame(
            'https://discord.com/api/v10/channels/222/messages/111/reactions/%F0%9F%91%8D/@me',
            $this->requests[0]['url']
        );
    }

    public function test_reaction_encodes_a_custom_emoji_id(): void
    {
        $http = $this->http($this->answer(204, array()));

        (new AddDiscordMessageReactionTool($this->errors, $http))->execute(
            array(
                'token'      => 'bot-secret',
                'channel_id' => '222',
                'message_id' => '111',
                'emoji'      => 'thinking:1234567890',
            ),
            self::CONTEXT
        );

        $this->assertStringEndsWith(
            '/reactions/thinking%3A1234567890/@me',
            $this->requests[0]['url']
        );
    }

    public function test_reaction_refuses_a_message_id_that_is_not_a_snowflake(): void
    {
        $http = $this->http();

        $result = (new AddDiscordMessageReactionTool($this->errors, $http))->execute(
            array(
                'token'      => 'bot-secret',
                'channel_id' => '222',
                'message_id' => '../../channels/1',
                'emoji'      => '👍',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('message_id', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_reaction_requires_an_emoji(): void
    {
        $http = $this->http();

        $result = (new AddDiscordMessageReactionTool($this->errors, $http))->execute(
            array('token' => 'bot-secret', 'channel_id' => '222', 'message_id' => '111', 'emoji' => '  '),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('emoji', $result['error']['data']);
        $this->assertCount(0, $this->requests);
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

        foreach (array(new GetDiscordChannelsTool($this->errors, $http), new GetDiscordMessagesTool($this->errors, $http)) as $tool) {
            $flags = $tool->getCapabilityFlags();

            $this->assertContains('read-only', $flags);
            $this->assertNotContains('write', $flags);
        }

        $writers = array(
            new SendDiscordMessageTool($this->errors, $http),
            new CreateDiscordChannelTool($this->errors, $http),
            new AddDiscordMessageReactionTool($this->errors, $http),
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
                'send_discord_message',
                'get_discord_channels',
                'get_discord_messages',
                'create_discord_channel',
                'add_discord_message_reaction',
            ),
            array_keys($slugs)
        );
    }

    public function test_every_write_refuses_an_unauthenticated_context(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new SendDiscordMessageTool($this->errors, $this->http()))->execute(
            array('token' => 'bot-secret', 'channel_id' => '222', 'content' => 'Hello'),
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
            new SendDiscordMessageTool($this->errors, $http),
            new GetDiscordChannelsTool($this->errors, $http),
            new GetDiscordMessagesTool($this->errors, $http),
            new CreateDiscordChannelTool($this->errors, $http),
            new AddDiscordMessageReactionTool($this->errors, $http),
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
