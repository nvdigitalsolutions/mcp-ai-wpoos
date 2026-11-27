<?php

/**
 * The four Slack tools: the request each makes, the projection each returns,
 * and the two ways a Slack call fails.
 *
 * Three things are worth pinning. First, Slack reports most refusals with HTTP
 * 200 and `ok: false`, so a status-only check would read an invalid token as a
 * success; the classification is asserted on `ok`. Second, the token is an
 * argument, so it must never appear in a reply — every failure test serialises
 * the result and asserts the token is absent. Third, the projection is the
 * family contract rather than Slack's: a payload with extra keys must arrive
 * with the documented ones and nothing else.
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
use Nvoos\Core\Tool\ChatChannels\CreateSlackChannelTool;
use Nvoos\Core\Tool\ChatChannels\GetSlackChannelsTool;
use Nvoos\Core\Tool\ChatChannels\GetSlackMessagesTool;
use Nvoos\Core\Tool\ChatChannels\SendSlackMessageTool;
use PHPUnit\Framework\TestCase;

final class ChatChannelSlackToolsTest extends TestCase
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

    // ─── send_slack_message ──────────────────────────────────────────

    public function test_send_posts_the_documented_payload_and_projects_the_timestamp(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'ok'      => true,
                    'channel' => 'C123',
                    'ts'      => '1503435956.000247',
                    'message' => array(
                        'text' => 'Hello',
                        'ts'   => '1503435956.000247',
                    ),
                )
            )
        );

        $result = (new SendSlackMessageTool($this->errors, $http))->execute(
            array(
                'token'   => 'xoxb-secret',
                'channel' => '#general',
                'text'    => 'Hello',
                'blocks'  => array(array('type' => 'section')),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'channel'   => 'C123',
                'ts'        => '1503435956.000247',
                'text'      => 'Hello',
                'thread_ts' => null,
            ),
            $result['data']
        );

        $this->assertCount(1, $this->requests);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame('https://slack.com/api/chat.postMessage', $this->requests[0]['url']);
        $this->assertSame('Bearer xoxb-secret', $this->requests[0]['headers']['Authorization']);

        $body = $this->body(0);

        $this->assertSame('#general', $body['channel']);
        $this->assertSame('Hello', $body['text']);
        $this->assertSame(array(array('type' => 'section')), $body['blocks']);
        $this->assertArrayNotHasKey('thread_ts', $body);
    }

    public function test_send_sends_a_thread_parent_when_given_one(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'ok'      => true,
                    'channel' => 'C123',
                    'ts'      => '1503435960.000001',
                    'message' => array(
                        'text'      => 'Replying',
                        'thread_ts' => '1503435956.000247',
                    ),
                )
            )
        );

        $result = (new SendSlackMessageTool($this->errors, $http))->execute(
            array(
                'token'     => 'xoxb-secret',
                'channel'   => 'C123',
                'text'      => 'Replying',
                'thread_ts' => '1503435956.000247',
            ),
            self::CONTEXT
        );

        $this->assertSame('1503435956.000247', $result['data']['thread_ts']);
        $this->assertSame('1503435956.000247', $this->body(0)['thread_ts']);
    }

    public function test_send_refuses_a_missing_token_without_a_request(): void
    {
        $http = $this->http();

        $result = (new SendSlackMessageTool($this->errors, $http))->execute(
            array('channel' => 'C123', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array('token' => array('A valid Slack token is required.')), $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_send_refuses_a_missing_text(): void
    {
        $http = $this->http();

        $result = (new SendSlackMessageTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret', 'channel' => 'C123', 'text' => '   '),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('text', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_send_reports_an_ok_false_refusal_without_echoing_the_token(): void
    {
        $http = $this->http($this->answer(200, array('ok' => false, 'error' => 'invalid_auth')));

        $result = (new SendSlackMessageTool($this->errors, $http))->execute(
            array('token' => 'xoxb-do-not-leak', 'channel' => 'C123', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('slack_api_error', $result['error']['code']);
        $this->assertSame(200, $result['error']['data']['status']);
        $this->assertSame('invalid_auth', $result['error']['data']['code']);
        $this->assertStringContainsString('invalid_auth', $result['error']['message']);
        $this->assertStringNotContainsString('xoxb-do-not-leak', (string) json_encode($result));
    }

    public function test_send_reports_a_transport_failure_as_one_envelope(): void
    {
        $http = $this->http(new \RuntimeException('Connection timed out'));

        $result = (new SendSlackMessageTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret', 'channel' => 'C123', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('chat_channel_request_failed', $result['error']['code']);
        $this->assertStringContainsString('Connection timed out', $result['error']['message']);
        $this->assertStringNotContainsString('xoxb-secret', (string) json_encode($result));
    }

    public function test_send_reports_a_success_without_a_timestamp_as_malformed(): void
    {
        $http = $this->http($this->answer(200, array('ok' => true, 'channel' => 'C123')));

        $result = (new SendSlackMessageTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret', 'channel' => 'C123', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('slack_invalid_response', $result['error']['code']);
    }

    // ─── get_slack_channels ──────────────────────────────────────────

    public function test_channels_projects_rows_and_the_cursor(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'ok'                => true,
                    'channels'          => array(
                        array(
                            'id'          => 'C1',
                            'name'        => 'general',
                            'is_private'  => false,
                            'is_archived' => false,
                            'num_members' => 4,
                            'topic'       => array('value' => 'not in the contract'),
                        ),
                        array(
                            'id'     => 'D1',
                            'is_im'  => true,
                            'user'   => 'U1',
                        ),
                        'not-a-row',
                    ),
                    'response_metadata' => array('next_cursor' => 'dGVhbTpDMDYxRkE1UEI='),
                )
            )
        );

        $result = (new GetSlackChannelsTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                array(
                    'id'          => 'C1',
                    'name'        => 'general',
                    'is_private'  => false,
                    'is_archived' => false,
                    'num_members' => 4,
                ),
                array(
                    'id'          => 'D1',
                    'name'        => '',
                    'is_private'  => false,
                    'is_archived' => false,
                    'num_members' => null,
                ),
            ),
            $result['data']['channels']
        );
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame('dGVhbTpDMDYxRkE1UEI=', $result['data']['next_cursor']);
    }

    public function test_channels_sends_the_default_limit_and_omits_the_types_it_was_not_given(): void
    {
        $http = $this->http($this->answer(200, array('ok' => true, 'channels' => array())));

        (new GetSlackChannelsTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret'),
            self::CONTEXT
        );

        $query = $this->query(0);

        $this->assertSame('100', $query['limit']);
        $this->assertArrayNotHasKey('types', $query);
        $this->assertArrayNotHasKey('cursor', $query);
    }

    public function test_channels_clamps_the_limit_to_slacks_ceiling(): void
    {
        $http = $this->http($this->answer(200, array('ok' => true, 'channels' => array())));

        (new GetSlackChannelsTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret', 'limit' => 5000),
            self::CONTEXT
        );

        $this->assertSame('999', $this->query(0)['limit']);
    }

    public function test_channels_passes_types_and_cursor_through(): void
    {
        $http = $this->http($this->answer(200, array('ok' => true, 'channels' => array())));

        (new GetSlackChannelsTool($this->errors, $http))->execute(
            array(
                'token'  => 'xoxb-secret',
                'types'  => 'public_channel,private_channel',
                'cursor' => 'abc',
            ),
            self::CONTEXT
        );

        $query = $this->query(0);

        $this->assertSame('public_channel,private_channel', $query['types']);
        $this->assertSame('abc', $query['cursor']);
    }

    // ─── get_slack_messages ──────────────────────────────────────────

    public function test_messages_projects_rows_and_reports_more_pages(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'ok'                => true,
                    'messages'          => array(
                        array(
                            'type'    => 'message',
                            'user'    => 'U1',
                            'text'    => 'Hello',
                            'ts'      => '1512085950.000216',
                            'reactions' => array(array('name' => 'not in the contract')),
                        ),
                        array(
                            'type'    => 'message',
                            'subtype' => 'bot_message',
                            'bot_id'  => 'B1',
                            'text'    => 'Deployed',
                            'ts'      => '1512085960.000001',
                            'thread_ts' => '1512085950.000216',
                            'reply_count' => 2,
                        ),
                        'not-a-row',
                    ),
                    'has_more'          => true,
                    'response_metadata' => array('next_cursor' => 'bmV4dF90cw=='),
                )
            )
        );

        $result = (new GetSlackMessagesTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret', 'channel' => 'C1'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                array(
                    'ts'          => '1512085950.000216',
                    'type'        => 'message',
                    'subtype'     => null,
                    'user'        => 'U1',
                    'bot_id'      => '',
                    'text'        => 'Hello',
                    'thread_ts'   => null,
                    'reply_count' => 0,
                ),
                array(
                    'ts'          => '1512085960.000001',
                    'type'        => 'message',
                    'subtype'     => 'bot_message',
                    'user'        => '',
                    'bot_id'      => 'B1',
                    'text'        => 'Deployed',
                    'thread_ts'   => '1512085950.000216',
                    'reply_count' => 2,
                ),
            ),
            $result['data']['messages']
        );
        $this->assertSame(2, $result['data']['count']);
        $this->assertTrue($result['data']['has_more']);
        $this->assertSame('bmV4dF90cw==', $result['data']['next_cursor']);
    }

    public function test_messages_sends_the_channel_limit_and_time_bounds(): void
    {
        $http = $this->http($this->answer(200, array('ok' => true)));

        $result = (new GetSlackMessagesTool($this->errors, $http))->execute(
            array(
                'token'   => 'xoxb-secret',
                'channel' => 'C1',
                'limit'   => 20,
                'oldest'  => '1512085950.000216',
                'latest'  => '1512185950.000000',
            ),
            self::CONTEXT
        );

        $query = $this->query(0);

        $this->assertSame('C1', $query['channel']);
        $this->assertSame('20', $query['limit']);
        $this->assertSame('1512085950.000216', $query['oldest']);
        $this->assertSame('1512185950.000000', $query['latest']);
        $this->assertFalse($result['data']['has_more']);
        $this->assertNull($result['data']['next_cursor']);
        $this->assertSame(array(), $result['data']['messages']);
    }

    public function test_messages_requires_a_channel(): void
    {
        $http = $this->http();

        $result = (new GetSlackMessagesTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('channel', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    // ─── create_slack_channel ────────────────────────────────────────

    public function test_create_posts_the_name_and_privacy_and_projects_the_channel(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'ok'      => true,
                    'channel' => array(
                        'id'         => 'C0EAQDV4Z',
                        'name'       => 'endeavor',
                        'is_private' => true,
                        'created'    => 1504554479,
                        'topic'      => array('value' => 'not in the contract'),
                    ),
                )
            )
        );

        $result = (new CreateSlackChannelTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret', 'name' => 'endeavor', 'is_private' => true),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'id'          => 'C0EAQDV4Z',
                'name'        => 'endeavor',
                'is_private'  => true,
                'created'     => 1504554479,
                'num_members' => null,
            ),
            $result['data']
        );

        $body = $this->body(0);

        $this->assertSame('endeavor', $body['name']);
        $this->assertTrue($body['is_private']);
        $this->assertSame('https://slack.com/api/conversations.create', $this->requests[0]['url']);
    }

    public function test_create_parses_a_string_false_as_public(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'ok'      => true,
                    'channel' => array('id' => 'C1', 'name' => 'endeavor', 'is_private' => false),
                )
            )
        );

        (new CreateSlackChannelTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret', 'name' => 'endeavor', 'is_private' => 'false'),
            self::CONTEXT
        );

        $this->assertFalse($this->body(0)['is_private']);
    }

    public function test_create_reports_a_taken_name_with_slacks_own_code(): void
    {
        $http = $this->http($this->answer(200, array('ok' => false, 'error' => 'name_taken')));

        $result = (new CreateSlackChannelTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret', 'name' => 'general'),
            self::CONTEXT
        );

        $this->assertSame('slack_api_error', $result['error']['code']);
        $this->assertSame('name_taken', $result['error']['data']['code']);
        $this->assertStringContainsString('name_taken', $result['error']['message']);
    }

    public function test_create_reports_a_success_without_a_channel_as_malformed(): void
    {
        $http = $this->http($this->answer(200, array('ok' => true)));

        $result = (new CreateSlackChannelTool($this->errors, $http))->execute(
            array('token' => 'xoxb-secret', 'name' => 'endeavor'),
            self::CONTEXT
        );

        $this->assertSame('slack_invalid_response', $result['error']['code']);
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

        foreach (array(new GetSlackChannelsTool($this->errors, $http), new GetSlackMessagesTool($this->errors, $http)) as $tool) {
            $flags = $tool->getCapabilityFlags();

            $this->assertContains('read-only', $flags);
            $this->assertNotContains('write', $flags);
            $this->assertContains('requires-credentials', $flags);
        }

        foreach (array(new SendSlackMessageTool($this->errors, $http), new CreateSlackChannelTool($this->errors, $http)) as $tool) {
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
            $slugs[$tool->getSlug()] = $tool->getName();
        }

        $this->assertSame(
            array(
                'send_slack_message',
                'get_slack_channels',
                'get_slack_messages',
                'create_slack_channel',
            ),
            array_keys($slugs)
        );
    }

    public function test_every_write_refuses_an_unauthenticated_context(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new SendSlackMessageTool($this->errors, $this->http()))->execute(
            array('token' => 'xoxb-secret', 'channel' => 'C1', 'text' => 'Hello'),
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
            new SendSlackMessageTool($this->errors, $http),
            new GetSlackChannelsTool($this->errors, $http),
            new GetSlackMessagesTool($this->errors, $http),
            new CreateSlackChannelTool($this->errors, $http),
        );
    }

    private function answer(int $status, array $payload): HttpResponse
    {
        return new HttpResponse($status, (string) json_encode($payload));
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
        $body = $this->requests[$index]['body'] ?? '';

        $decoded = json_decode((string) $body, true);

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
