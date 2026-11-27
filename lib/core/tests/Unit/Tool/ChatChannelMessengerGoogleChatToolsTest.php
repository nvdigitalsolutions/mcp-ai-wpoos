<?php

/**
 * Messenger and Google Chat: the routes, the projections, the webhook guard and
 * the two error shapes.
 *
 * The guard tests are the important ones here. `send_google_chat_message` takes
 * an endpoint from the caller and puts the credential **inside** it, so an
 * unchecked value is both an SSRF surface and a way to hand the key to whatever
 * host it names. Each refusal is asserted with an empty response queue, so a
 * request would throw rather than silently pass.
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
use Nvoos\Core\Tool\ChatChannels\AddGoogleChatSpaceMemberTool;
use Nvoos\Core\Tool\ChatChannels\CreateGoogleChatSpaceTool;
use Nvoos\Core\Tool\ChatChannels\GetGoogleChatMessagesTool;
use Nvoos\Core\Tool\ChatChannels\GetGoogleChatSpacesTool;
use Nvoos\Core\Tool\ChatChannels\GetMessengerConversationsTool;
use Nvoos\Core\Tool\ChatChannels\ListGoogleChatSpaceMembersTool;
use Nvoos\Core\Tool\ChatChannels\RemoveGoogleChatSpaceMemberTool;
use Nvoos\Core\Tool\ChatChannels\SendGoogleChatMessageTool;
use Nvoos\Core\Tool\ChatChannels\SendMessengerMessageTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChatChannelMessengerGoogleChatToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const TOKEN = 'google-secret';

    private const SPACE = 'spaces/AAAAAbCdEfG';

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

    // ─── Messenger ───────────────────────────────────────────────────

    public function test_messenger_send_posts_the_recipient_and_projects_the_result(): void
    {
        $http = $this->http($this->answer(200, array('recipient_id' => '123', 'message_id' => 'mid.1')));

        $result = (new SendMessengerMessageTool($this->errors, $http))->execute(
            array('access_token' => 'page-secret', 'recipient_id' => '1234567890', 'message' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                'recipient_id' => '1234567890',
                'message_id'   => 'mid.1',
            ),
            $result['data']
        );
        $this->assertSame('https://graph.facebook.com/v19.0/me/messages', $this->requests[0]['url']);
        $this->assertSame(
            array(
                'recipient' => array('id' => '1234567890'),
                'message'   => array('text' => 'Hello'),
            ),
            $this->body(0)
        );
    }

    public function test_messenger_send_refuses_a_non_numeric_psid(): void
    {
        $http = $this->http();

        $result = (new SendMessengerMessageTool($this->errors, $http))->execute(
            array('access_token' => 'page-secret', 'recipient_id' => 'abc/def', 'message' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('recipient_id', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_messenger_conversations_projects_rows_and_the_cursor(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'data'   => array(
                        array('id' => 't_1', 'snippet' => 'Hi there'),
                        'not-a-row',
                    ),
                    'paging' => array(
                        'cursors' => array('after' => 'CURSOR'),
                        'next'    => 'https://graph.facebook.com/v19.0/me/conversations?after=CURSOR&access_token=page-secret',
                    ),
                )
            )
        );

        $result = (new GetMessengerConversationsTool($this->errors, $http))->execute(
            array('access_token' => 'page-secret'),
            self::CONTEXT
        );

        $this->assertSame(
            array(array('id' => 't_1', 'snippet' => 'Hi there')),
            $result['data']['conversations']
        );
        $this->assertSame('CURSOR', $result['data']['next_cursor']);
        $this->assertSame(25, $result['data']['limit']);
        $this->assertArrayNotHasKey('next', $result['data']);
        $this->assertStringNotContainsString('page-secret', (string) json_encode($result));

        $query = $this->query(0);

        $this->assertSame('messenger', $query['platform']);
        $this->assertSame('id,snippet', $query['fields']);
        $this->assertSame('25', $query['limit']);
    }

    public function test_messenger_reports_the_provider_code(): void
    {
        $http = $this->http(
            $this->answer(
                400,
                array(
                    'error' => array(
                        'message' => 'Invalid parameter',
                        'type'    => 'OAuthException',
                        'code'    => 100,
                    ),
                )
            )
        );

        $result = (new GetMessengerConversationsTool($this->errors, $http))->execute(
            array('access_token' => 'page-secret'),
            self::CONTEXT
        );

        $this->assertSame('messenger_api_error', $result['error']['code']);
        $this->assertSame(400, $result['error']['data']['status']);
        $this->assertSame('100', $result['error']['data']['code']);
        $this->assertStringNotContainsString('page-secret', (string) json_encode($result));
    }

    // ─── Google Chat: the webhook guard ──────────────────────────────

    public function test_webhook_send_posts_the_text_and_does_not_echo_the_url(): void
    {
        $http = $this->http(
            $this->answer(200, array('name' => 'spaces/AAAA/messages/BBB', 'text' => 'Hello'))
        );

        $result = (new SendGoogleChatMessageTool($this->errors, $http))->execute(
            array(
                'webhook_url' => 'https://chat.googleapis.com/v1/spaces/AAAA/messages?key=SECRET&token=TOKEN',
                'text'        => 'Hello',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('spaces/AAAA/messages/BBB', $result['data']['message_name']);
        $this->assertSame('Hello', $result['data']['text']);
        $this->assertSame(array('text' => 'Hello'), $this->body(0));
        $this->assertStringNotContainsString('SECRET', (string) json_encode($result));
        $this->assertStringNotContainsString('TOKEN', (string) json_encode($result));
    }

    #[DataProvider('refusedUrls')]
    public function test_webhook_refuses_an_unsafe_url_without_a_request(string $url): void
    {
        $http = $this->http();

        $result = (new SendGoogleChatMessageTool($this->errors, $http))->execute(
            array('webhook_url' => $url, 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('webhook_url', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function refusedUrls(): array
    {
        return array(
            'plain http'        => array('http://chat.googleapis.com/v1/spaces/AAAA/messages?key=x'),
            'loopback ip'       => array('https://127.0.0.1/hook'),
            'link-local ip'     => array('https://169.254.169.254/latest/meta-data'),
            'private ip'        => array('https://10.0.0.5/hook'),
            'localhost'         => array('https://localhost/hook'),
            'local suffix'      => array('https://printer.local/hook'),
            'userinfo'          => array('https://user:pass@example.com/hook'),
            'not a url'         => array('chat.googleapis.com/hook'),
        );
    }

    public function test_webhook_reports_a_provider_refusal(): void
    {
        $http = $this->http(
            $this->answer(403, array('error' => array('code' => 403, 'status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission')))
        );

        $result = (new SendGoogleChatMessageTool($this->errors, $http))->execute(
            array('webhook_url' => 'https://chat.googleapis.com/v1/spaces/AAAA/messages?key=x', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('google_chat_api_error', $result['error']['code']);
        $this->assertSame(403, $result['error']['data']['status']);
        $this->assertSame('PERMISSION_DENIED', $result['error']['data']['code']);
        $this->assertStringContainsString('does not have permission', $result['error']['message']);
    }

    // ─── Google Chat: spaces ─────────────────────────────────────────

    public function test_spaces_projects_rows_and_the_page_token(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'spaces'        => array(
                        array(
                            'name'        => self::SPACE,
                            'displayName' => 'Team',
                            'spaceType'   => 'SPACE',
                            'threadingState' => 'not in the contract',
                        ),
                        'not-a-row',
                    ),
                    'nextPageToken' => 'PAGE2',
                )
            )
        );

        $result = (new GetGoogleChatSpacesTool($this->errors, $http))->execute(
            array('access_token' => self::TOKEN),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                array(
                    'name'         => self::SPACE,
                    'display_name' => 'Team',
                    'type'         => 'SPACE',
                ),
            ),
            $result['data']['spaces']
        );
        $this->assertSame('PAGE2', $result['data']['next_page_token']);
        $this->assertSame('100', $this->query(0)['pageSize']);
    }

    public function test_create_space_posts_the_type_and_projects_the_result(): void
    {
        $http = $this->http(
            $this->answer(200, array('name' => self::SPACE, 'displayName' => 'Team', 'spaceType' => 'GROUP_CHAT'))
        );

        $result = (new CreateGoogleChatSpaceTool($this->errors, $http))->execute(
            array('access_token' => self::TOKEN, 'display_name' => 'Team', 'space_type' => 'GROUP_CHAT'),
            self::CONTEXT
        );

        $this->assertSame(
            array('name' => self::SPACE, 'display_name' => 'Team', 'type' => 'GROUP_CHAT'),
            $result['data']
        );
        $this->assertSame(
            array('spaceType' => 'GROUP_CHAT', 'displayName' => 'Team'),
            $this->body(0)
        );
        $this->assertSame('https://chat.googleapis.com/v1/spaces', $this->requests[0]['url']);
    }

    public function test_create_space_refuses_an_unsupported_type(): void
    {
        $http = $this->http();

        $result = (new CreateGoogleChatSpaceTool($this->errors, $http))->execute(
            array('access_token' => self::TOKEN, 'display_name' => 'Team', 'space_type' => 'FORUM'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    // ─── Google Chat: messages ───────────────────────────────────────

    public function test_messages_projects_rows(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'messages' => array(
                        array(
                            'name'       => self::SPACE . '/messages/BBB',
                            'text'       => 'Hello',
                            'createTime' => '2026-11-27T10:00:00Z',
                            'sender'     => array('name' => 'users/123', 'displayName' => 'Alice'),
                            'thread'     => array('name' => self::SPACE . '/threads/T1'),
                            'cards'      => array('not in the contract'),
                        ),
                    ),
                )
            )
        );

        $result = (new GetGoogleChatMessagesTool($this->errors, $http))->execute(
            array('access_token' => self::TOKEN, 'space' => self::SPACE),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                array(
                    'name'                => self::SPACE . '/messages/BBB',
                    'text'                => 'Hello',
                    'create_time'         => '2026-11-27T10:00:00Z',
                    'sender_name'         => 'users/123',
                    'sender_display_name' => 'Alice',
                    'thread_name'         => self::SPACE . '/threads/T1',
                ),
            ),
            $result['data']['messages']
        );
        $this->assertSame(50, $result['data']['page_size']);
        $this->assertStringStartsWith(
            'https://chat.googleapis.com/v1/' . self::SPACE . '/messages',
            $this->requests[0]['url']
        );
        $this->assertSame('50', $this->query(0)['pageSize']);
    }

    public function test_messages_refuses_an_invalid_space_name(): void
    {
        $http = $this->http();

        $result = (new GetGoogleChatMessagesTool($this->errors, $http))->execute(
            array('access_token' => self::TOKEN, 'space' => 'spaces/../users'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('space', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    // ─── Google Chat: members ────────────────────────────────────────

    public function test_members_projects_rows(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'memberships' => array(
                        array(
                            'name'   => self::SPACE . '/members/MMM',
                            'member' => array('name' => 'users/123', 'displayName' => 'Alice', 'type' => 'HUMAN'),
                            'role'   => 'ROLE_MEMBER',
                            'state'  => 'JOINED',
                        ),
                    ),
                )
            )
        );

        $result = (new ListGoogleChatSpaceMembersTool($this->errors, $http))->execute(
            array('access_token' => self::TOKEN, 'space' => self::SPACE),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                array(
                    'name'                => self::SPACE . '/members/MMM',
                    'member_name'         => 'users/123',
                    'member_display_name' => 'Alice',
                    'member_type'         => 'HUMAN',
                    'role'                => 'ROLE_MEMBER',
                    'state'               => 'JOINED',
                ),
            ),
            $result['data']['members']
        );
    }

    public function test_add_member_posts_the_member_and_projects_the_membership(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'name'   => self::SPACE . '/members/MMM',
                    'member' => array('name' => 'users/123', 'displayName' => 'Alice'),
                    'role'   => 'ROLE_MANAGER',
                    'state'  => 'JOINED',
                )
            )
        );

        $result = (new AddGoogleChatSpaceMemberTool($this->errors, $http))->execute(
            array(
                'access_token' => self::TOKEN,
                'space'        => self::SPACE,
                'member_name'  => 'users/123',
                'role'         => 'ROLE_MANAGER',
            ),
            self::CONTEXT
        );

        $this->assertSame(self::SPACE . '/members/MMM', $result['data']['membership']);
        $this->assertSame('ROLE_MANAGER', $result['data']['role']);

        $body = $this->body(0);

        $this->assertSame('users/123', $body['member']['name']);
        $this->assertSame('HUMAN', $body['member']['type']);
        $this->assertSame('ROLE_MANAGER', $body['role']);
    }

    public function test_add_member_infers_bot_for_a_group(): void
    {
        $http = $this->http($this->answer(200, array('name' => self::SPACE . '/members/MMM')));

        (new AddGoogleChatSpaceMemberTool($this->errors, $http))->execute(
            array('access_token' => self::TOKEN, 'space' => self::SPACE, 'member_name' => 'groups/abc@example.com'),
            self::CONTEXT
        );

        $this->assertSame('BOT', $this->body(0)['member']['type']);
    }

    public function test_add_member_refuses_an_unsupported_role(): void
    {
        $http = $this->http();

        $result = (new AddGoogleChatSpaceMemberTool($this->errors, $http))->execute(
            array(
                'access_token' => self::TOKEN,
                'space'        => self::SPACE,
                'member_name'  => 'users/123',
                'role'         => 'ROLE_OWNER',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('role', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_remove_member_deletes_the_membership_and_reports_it(): void
    {
        $http = $this->http($this->answer(200, array()));

        $result = (new RemoveGoogleChatSpaceMemberTool($this->errors, $http))->execute(
            array('access_token' => self::TOKEN, 'membership' => self::SPACE . '/members/MMM'),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                'membership' => self::SPACE . '/members/MMM',
                'removed'    => true,
            ),
            $result['data']
        );
        $this->assertSame('DELETE', $this->requests[0]['method']);
        $this->assertSame(
            'https://chat.googleapis.com/v1/' . self::SPACE . '/members/MMM',
            $this->requests[0]['url']
        );
    }

    public function test_remove_member_refuses_an_invalid_membership(): void
    {
        $http = $this->http();

        $result = (new RemoveGoogleChatSpaceMemberTool($this->errors, $http))->execute(
            array('access_token' => self::TOKEN, 'membership' => 'spaces/AAAA'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('membership', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    // ─── Family contract ─────────────────────────────────────────────

    public function test_reads_declare_read_only_and_writes_declare_write(): void
    {
        $http = $this->http();

        $reads = array(
            new GetMessengerConversationsTool($this->errors, $http),
            new GetGoogleChatSpacesTool($this->errors, $http),
            new GetGoogleChatMessagesTool($this->errors, $http),
            new ListGoogleChatSpaceMembersTool($this->errors, $http),
        );

        foreach ($reads as $tool) {
            $flags = $tool->getCapabilityFlags();

            $this->assertContains('read-only', $flags);
            $this->assertNotContains('write', $flags);
        }

        $writes = array(
            new SendMessengerMessageTool($this->errors, $http),
            new SendGoogleChatMessageTool($this->errors, $http),
            new CreateGoogleChatSpaceTool($this->errors, $http),
            new AddGoogleChatSpaceMemberTool($this->errors, $http),
            new RemoveGoogleChatSpaceMemberTool($this->errors, $http),
        );

        foreach ($writes as $tool) {
            $flags = $tool->getCapabilityFlags();

            $this->assertContains('state-changing', $flags);
            $this->assertContains('write', $flags);
            $this->assertNotContains('read-only', $flags);
        }
    }

    public function test_every_tool_declares_its_documented_slug(): void
    {
        $http = $this->http();

        $slugs = array(
            (new SendMessengerMessageTool($this->errors, $http))->getSlug(),
            (new GetMessengerConversationsTool($this->errors, $http))->getSlug(),
            (new SendGoogleChatMessageTool($this->errors, $http))->getSlug(),
            (new GetGoogleChatSpacesTool($this->errors, $http))->getSlug(),
            (new CreateGoogleChatSpaceTool($this->errors, $http))->getSlug(),
            (new GetGoogleChatMessagesTool($this->errors, $http))->getSlug(),
            (new ListGoogleChatSpaceMembersTool($this->errors, $http))->getSlug(),
            (new AddGoogleChatSpaceMemberTool($this->errors, $http))->getSlug(),
            (new RemoveGoogleChatSpaceMemberTool($this->errors, $http))->getSlug(),
        );

        $this->assertSame(
            array(
                'send_messenger_message',
                'get_messenger_conversations',
                'send_google_chat_message',
                'get_google_chat_spaces',
                'create_google_chat_space',
                'get_google_chat_messages',
                'list_google_chat_space_members',
                'add_google_chat_space_member',
                'remove_google_chat_space_member',
            ),
            $slugs
        );
    }

    public function test_a_write_refuses_an_unauthenticated_context(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new SendGoogleChatMessageTool($this->errors, $this->http()))->execute(
            array('webhook_url' => 'https://chat.googleapis.com/v1/spaces/AAAA/messages?key=x', 'text' => 'Hello'),
            array()
        );
    }

    // ─── Helpers ─────────────────────────────────────────────────────

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
