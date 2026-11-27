<?php

/**
 * The unified broadcaster: the fan-out, its report, and the envelope.
 *
 * The tool sends nothing itself. It resolves each channel's sender through
 * `ToolResolverInterface` and delegates, which is the one seam that keeps the
 * provider's failure detail — `ToolInvokerInterface` would collapse it to null.
 *
 * Four things are worth pinning.
 *
 * First, the envelope. WordPress returned `success` as an *array of channel
 * results*, so any caller reading the canonical `success => true` saw a working
 * broadcast as a failure. That is the first test here.
 *
 * Second, the three outcomes. All sent is a success; some sent is a success with
 * the failures listed; none sent is an error, because a broadcast that reached
 * nobody must not look like a success with a nested counter.
 *
 * Third, fail-fast on a typo. An unknown channel name refuses the whole call
 * before anything is sent, where WordPress broadcast to the valid channels and
 * reported the typo as one more failure.
 *
 * Fourth, the delegation itself: the argument each sender receives, the
 * per-channel failure rows, and the removal of the duplicate send.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Contract\ToolResolverInterface;
use Nvoos\Core\Tool\ChatChannels\UnifiedChannelBroadcastTool;
use PHPUnit\Framework\TestCase;

final class ChatChannelBroadcastToolTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private ErrorFactoryInterface $errors;

    /** @var array<string, object> */
    private array $senders = array();

    protected function setUp(): void
    {
        $this->senders = array();
        $this->errors  = $this->createMock(ErrorFactoryInterface::class);

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

    public function test_a_broadcast_returns_the_canonical_success_envelope(): void
    {
        $telegram = $this->sender('send_telegram_message', array('success' => true));
        $slack    = $this->sender('send_slack_message', array('success' => true));

        $result = $this->tool()->execute(
            array(
                'message'     => '  Deploy is live.  ',
                'channels'    => array('telegram', 'slack'),
                'credentials' => array(
                    'telegram' => array('token' => 'tg-token', 'chat_id' => '-100'),
                    'slack'    => array('token' => 'sl-token', 'channel' => 'C1'),
                ),
            ),
            self::CONTEXT
        );

        // The WordPress tool put an array here, so `success === true` was false.
        $this->assertTrue($result['success']);
        $this->assertSame('Broadcast sent to 2 of 2 channel(s).', $result['message']);
        $this->assertSame(
            array('total' => 2, 'sent' => 2, 'failed' => 0),
            $result['data']['summary']
        );
        $this->assertSame(15, $result['data']['text_length']);

        $this->assertCount(1, $telegram->calls);
        $this->assertCount(1, $slack->calls);
    }

    public function test_each_sender_receives_its_own_argument_names(): void
    {
        $discord  = $this->sender('send_discord_message', array('success' => true));
        $teams    = $this->sender('send_teams_message', array('success' => true));
        $messenger = $this->sender('send_messenger_message', array('success' => true));
        $whatsapp = $this->sender('send_whatsapp_message', array('success' => true));
        $chat     = $this->sender('send_google_chat_message', array('success' => true));

        $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('discord', 'teams', 'messenger', 'whatsapp', 'google_chat'),
                'credentials' => array(
                    'discord'     => array('token' => 'd', 'channel_id' => 'c'),
                    'teams'       => array('token' => 't', 'team_id' => 'team', 'channel_id' => 'chan'),
                    'messenger'   => array('access_token' => 'm', 'recipient_id' => 'psid'),
                    'whatsapp'    => array('access_token' => 'w', 'phone_number_id' => 'pn', 'to' => '+1'),
                    'google_chat' => array('webhook_url' => 'https://chat.example.com/hook'),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame(
            array('token' => 'd', 'channel_id' => 'c', 'content' => 'Hello'),
            $discord->calls[0]['args']
        );
        $this->assertSame(
            array('token' => 't', 'team_id' => 'team', 'channel_id' => 'chan', 'content' => 'Hello'),
            $teams->calls[0]['args']
        );
        $this->assertSame(
            array('access_token' => 'm', 'recipient_id' => 'psid', 'message' => 'Hello'),
            $messenger->calls[0]['args']
        );
        $this->assertSame(
            array('access_token' => 'w', 'phone_number_id' => 'pn', 'to' => '+1', 'text' => 'Hello'),
            $whatsapp->calls[0]['args']
        );
        $this->assertSame(
            array('webhook_url' => 'https://chat.example.com/hook', 'text' => 'Hello'),
            $chat->calls[0]['args']
        );
    }

    public function test_a_partial_failure_is_a_success_with_the_failure_listed(): void
    {
        $this->sender('send_telegram_message', array('success' => true));
        $this->sender(
            'send_slack_message',
            array(
                'success' => false,
                'error'   => array(
                    'code'    => 'slack_api_error',
                    'message' => 'Slack API error: channel_not_found',
                    'data'    => array('status' => 404, 'code' => 'channel_not_found'),
                ),
            )
        );

        $result = $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('telegram', 'slack'),
                'credentials' => array(
                    'telegram' => array('token' => 'tg', 'chat_id' => '-1'),
                    'slack'    => array('token' => 'sl', 'channel' => 'C-missing'),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array('total' => 2, 'sent' => 1, 'failed' => 1), $result['data']['summary']);

        $slack = $result['data']['channels'][1];

        $this->assertFalse($slack['sent']);
        $this->assertSame('slack', $slack['channel']);
        $this->assertSame('send_slack_message', $slack['tool']);
        $this->assertSame('send_failed', $slack['error']['code']);
        $this->assertSame('Slack API error: channel_not_found', $slack['error']['message']);
        $this->assertSame('channel_not_found', $slack['error']['data']['code']);
    }

    public function test_a_broadcast_that_reached_nobody_is_an_error(): void
    {
        $refusal = array(
            'success' => false,
            'error'   => array('code' => 'telegram_api_error', 'message' => 'Telegram API error: chat not found'),
        );

        $this->sender('send_telegram_message', $refusal);
        $this->sender('send_slack_message', $refusal);

        $result = $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('telegram', 'slack'),
                'credentials' => array(
                    'telegram' => array('token' => 'tg', 'chat_id' => '-1'),
                    'slack'    => array('token' => 'sl', 'channel' => 'C1'),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('channel_broadcast_failed', $result['error']['code']);
        $this->assertSame(array('total' => 2, 'sent' => 0, 'failed' => 2), $result['error']['data']['summary']);
        $this->assertCount(2, $result['error']['data']['channels']);
    }

    public function test_an_unknown_channel_refuses_the_call_before_anything_is_sent(): void
    {
        $telegram = $this->sender('send_telegram_message', array('success' => true));

        $result = $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('telegram', 'telegramm'),
                'credentials' => array('telegram' => array('token' => 'tg', 'chat_id' => '-1')),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('channels', $result['error']['data']);
        $this->assertStringContainsString('telegramm', $result['error']['message']);
        $this->assertCount(0, $telegram->calls);
    }

    public function test_a_repeated_channel_is_sent_to_once(): void
    {
        $telegram = $this->sender('send_telegram_message', array('success' => true));

        $result = $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('telegram', 'telegram'),
                'credentials' => array('telegram' => array('token' => 'tg', 'chat_id' => '-1')),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['summary']['total']);
        $this->assertCount(1, $telegram->calls);
    }

    public function test_a_channel_with_no_credentials_fails_alone(): void
    {
        $telegram = $this->sender('send_telegram_message', array('success' => true));

        $result = $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('telegram', 'slack'),
                'credentials' => array('telegram' => array('token' => 'tg', 'chat_id' => '-1')),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['summary']['sent']);
        $this->assertSame('missing_credentials', $result['data']['channels'][1]['error']['code']);
        $this->assertCount(1, $telegram->calls);
    }

    public function test_a_missing_credential_key_is_named(): void
    {
        $this->sender('send_whatsapp_message', array('success' => true));

        $result = $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('whatsapp'),
                'credentials' => array(
                    'whatsapp' => array('access_token' => 'w', 'to' => '+1'),
                ),
            ),
            self::CONTEXT
        );

        // One channel, and it failed, so the whole broadcast is an error.
        $this->assertSame('channel_broadcast_failed', $result['error']['code']);
        $this->assertSame('missing_credentials', $result['error']['data']['channels'][0]['error']['code']);
        $this->assertSame(array('phone_number_id'), $result['error']['data']['channels'][0]['error']['data']['missing']);
        $this->assertStringContainsString('phone_number_id', $result['error']['data']['channels'][0]['error']['message']);
    }

    public function test_an_unregistered_sender_is_reported_by_name(): void
    {
        // No sender is registered for slack in this test.
        $this->sender('send_telegram_message', array('success' => true));

        $result = $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('telegram', 'slack'),
                'credentials' => array(
                    'telegram' => array('token' => 'tg', 'chat_id' => '-1'),
                    'slack'    => array('token' => 'sl', 'channel' => 'C1'),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('tool_unavailable', $result['data']['channels'][1]['error']['code']);
        $this->assertStringContainsString('send_slack_message', $result['data']['channels'][1]['error']['message']);
    }

    public function test_json_encoded_credentials_are_decoded_at_both_levels(): void
    {
        $telegram = $this->sender('send_telegram_message', array('success' => true));

        $result = $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('telegram'),
                'credentials' => (string) json_encode(
                    array('telegram' => (string) json_encode(array('token' => 'tg', 'chat_id' => '-1')))
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array('token' => 'tg', 'chat_id' => '-1', 'text' => 'Hello'),
            $telegram->calls[0]['args']
        );
    }

    public function test_a_credential_string_that_is_not_an_object_is_refused_per_channel(): void
    {
        $result = $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('telegram'),
                'credentials' => array('telegram' => 'not json at all'),
            ),
            self::CONTEXT
        );

        $this->assertSame('channel_broadcast_failed', $result['error']['code']);
        $this->assertSame('invalid_credentials', $result['error']['data']['channels'][0]['error']['code']);
    }

    public function test_a_sender_that_throws_becomes_a_per_channel_failure(): void
    {
        $this->sender('send_telegram_message', new \RuntimeException('boom'));

        $result = $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('telegram', 'slack'),
                'credentials' => array(
                    'telegram' => array('token' => 'tg', 'chat_id' => '-1'),
                    'slack'    => array('token' => 'sl', 'channel' => 'C1'),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('tool_failed', $result['error']['data']['channels'][0]['error']['code']);
        $this->assertSame('boom', $result['error']['data']['channels'][0]['error']['message']);
    }

    public function test_an_empty_message_is_refused_without_resolving_a_sender(): void
    {
        $telegram = $this->sender('send_telegram_message', array('success' => true));

        $result = $this->tool()->execute(
            array(
                'message'     => '   ',
                'channels'    => array('telegram'),
                'credentials' => array('telegram' => array('token' => 'tg', 'chat_id' => '-1')),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('message', $result['error']['data']);
        $this->assertCount(0, $telegram->calls);
    }

    public function test_an_empty_channel_list_is_refused(): void
    {
        $result = $this->tool()->execute(
            array('message' => 'Hello', 'channels' => array(), 'credentials' => array('telegram' => array())),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('channels', $result['error']['data']);
    }

    public function test_the_context_is_passed_to_every_sender(): void
    {
        $telegram = $this->sender('send_telegram_message', array('success' => true));
        $slack    = $this->sender('send_slack_message', array('success' => true));

        $this->tool()->execute(
            array(
                'message'     => 'Hello',
                'channels'    => array('telegram', 'slack'),
                'credentials' => array(
                    'telegram' => array('token' => 'tg', 'chat_id' => '-1'),
                    'slack'    => array('token' => 'sl', 'channel' => 'C1'),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame(self::CONTEXT, $telegram->calls[0]['context']);
        $this->assertSame(self::CONTEXT, $slack->calls[0]['context']);
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function tool(): UnifiedChannelBroadcastTool
    {
        $http = $this->createMock(HttpClientInterface::class);

        return new UnifiedChannelBroadcastTool($this->errors, $http, $this->resolver());
    }

    private function resolver(): ToolResolverInterface
    {
        $senders = $this->senders;

        return new class ($senders) implements ToolResolverInterface {
            /**
             * @param array<string, ToolInterface> $senders Senders by slug.
             */
            public function __construct(private readonly array $senders)
            {
            }

            public function get(string $slug): ?ToolInterface
            {
                return $this->senders[$slug] ?? null;
            }

            public function has(string $slug): bool
            {
                return isset($this->senders[$slug]);
            }
        };
    }

    /**
     * A recording sender. `$result` is returned from execute(), or thrown when
     * it is a Throwable.
     */
    private function sender(string $slug, mixed $result): object
    {
        $sender = new class ($result) implements ToolInterface {
            /** @var list<array{args: array<string, mixed>, context: array<string, mixed>}> */
            public array $calls = array();

            public function __construct(private readonly mixed $result)
            {
            }

            public function getSlug(): string
            {
                return 'fake_sender';
            }

            public function getName(): string
            {
                return 'Fake sender';
            }

            public function getDescription(): string
            {
                return 'Test double.';
            }

            public function getParametersSchema(): array
            {
                return array('type' => 'object');
            }

            public function getRequiredCapability(): string
            {
                return 'manage_options';
            }

            public function execute(array $arguments = array(), array $context = array()): mixed
            {
                $this->calls[] = array('args' => $arguments, 'context' => $context);

                if ($this->result instanceof \Throwable) {
                    throw $this->result;
                }

                return $this->result;
            }
        };

        $this->senders[$slug] = $sender;

        return $sender;
    }
}
