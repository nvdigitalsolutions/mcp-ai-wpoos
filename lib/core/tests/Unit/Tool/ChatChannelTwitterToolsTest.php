<?php

/**
 * The three Twitter/X tools: the OAuth 1.0a signer, the two DM routes, and the
 * four Account Activity actions.
 *
 * Four things are worth pinning.
 *
 * First, the signer. X publishes a worked example of the signature base string
 * and the resulting HMAC-SHA1 digest; `test_the_signature_matches_the_documented_example`
 * reproduces it byte for byte, which is a check against the provider's own
 * arithmetic rather than against this implementation's.
 *
 * Second, the ids. Both the user id and the conversation id were interpolated
 * into a path by WordPress after `sanitize_text_field()`; a `/` or a dot
 * sequence would address a different route. Every invalid shape must be refused
 * without a request.
 *
 * Third, the environment name. Webhook management is the v1.1 Account Activity
 * API, scoped per environment; the `/2/webhooks` route WordPress fell back to
 * does not exist. An absent `env_name` is refused, not guessed.
 *
 * Fourth, the failure shapes. X answers with an RFC 9457 problem document, so
 * `detail` is the message and `status` is the provider code; a 429 carries
 * `x-rate-limit-reset` as a Unix timestamp rather than a delay.
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
use Nvoos\Core\Tool\ChatChannels\GetTwitterDmsTool;
use Nvoos\Core\Tool\ChatChannels\ManageTwitterWebhookTool;
use Nvoos\Core\Tool\ChatChannels\SendTwitterDmTool;
use Nvoos\Core\Tool\ChatChannels\TwitterTool;
use PHPUnit\Framework\TestCase;

final class ChatChannelTwitterToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const RECIPIENT = '2244994945';

    private const CONVERSATION = '1589490931234567890';

    /**
     * The OAuth 1.0a credentials the signer tests use. The secrets are long
     * enough that a chance substring match in a nonce or a digest is not a
     * plausible explanation for a leak assertion passing.
     */
    private const CREDENTIALS = array(
        'api_key'             => 'consumer-key-3nR4',
        'api_secret'          => 'consumer-secret-Zq7Lm2',
        'access_token'        => 'access-token-9vT1',
        'access_token_secret' => 'access-secret-Kd5Bx8',
    );

    /**
     * X's published example, "Creating a signature": the expected base string.
     */
    private const DOCUMENTED_BASE = 'POST&https%3A%2F%2Fapi.twitter.com%2F1.1%2Fstatuses%2Fupdate.json'
        . '&include_entities%3Dtrue'
        . '%26oauth_consumer_key%3Dxvz1evFS4wEEPTGEFPHBog'
        . '%26oauth_nonce%3DkYjzVBB8Y0ZFabxSWbWovY3uYSQ2pTgmZeNu2VS4cg'
        . '%26oauth_signature_method%3DHMAC-SHA1'
        . '%26oauth_timestamp%3D1318622958'
        . '%26oauth_token%3D370773112-GmHxMAgYyLbNEtIKZeRNFsMKPR9EyMZeS9weJAEb'
        . '%26oauth_version%3D1.0'
        . '%26status%3DHello%2520Ladies%2520%252B%2520Gentlemen%252C'
        . '%2520a%2520signed%2520OAuth%2520request%2521';

    /**
     * The digest X's example reports for that base string and key.
     */
    private const DOCUMENTED_SIGNATURE = 'hCtSmYh+iHYCEqBWrE7C7hYmtUk=';

    private const DOCUMENTED_CONSUMER_SECRET = 'kAcSOqF21Fu85e7zjz7ZN2U4ZRhfV3WpwPAoE3Z7kBw';

    private const DOCUMENTED_TOKEN_SECRET = 'LswwdoUaIvS8ltyTt5jkRh4J50vUPVVHtR2YPi5kE';

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

    // ─── the OAuth 1.0a signer ───────────────────────────────────────

    public function test_the_signature_matches_the_documented_example(): void
    {
        $parameters = array(
            'oauth_consumer_key'     => 'xvz1evFS4wEEPTGEFPHBog',
            'oauth_nonce'            => 'kYjzVBB8Y0ZFabxSWbWovY3uYSQ2pTgmZeNu2VS4cg',
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => '1318622958',
            'oauth_token'            => '370773112-GmHxMAgYyLbNEtIKZeRNFsMKPR9EyMZeS9weJAEb',
            'oauth_version'          => '1.0',
            'status'                 => 'Hello Ladies + Gentlemen, a signed OAuth request!',
            'include_entities'       => 'true',
        );

        $this->assertSame(
            self::DOCUMENTED_BASE,
            TwitterTool::signatureBaseString(
                'POST',
                'https://api.twitter.com/1.1/statuses/update.json',
                $parameters
            )
        );

        $this->assertSame(
            self::DOCUMENTED_SIGNATURE,
            TwitterTool::sign(
                'POST',
                'https://api.twitter.com/1.1/statuses/update.json',
                $parameters,
                self::DOCUMENTED_CONSUMER_SECRET,
                self::DOCUMENTED_TOKEN_SECRET
            )
        );
    }

    public function test_the_base_uri_drops_the_query_and_a_default_port(): void
    {
        $base = TwitterTool::signatureBaseString(
            'GET',
            'https://API.Twitter.com:443/2/dm_events?max_results=10',
            array('oauth_version' => '1.0')
        );

        $this->assertSame(
            'GET&https%3A%2F%2Fapi.twitter.com%2F2%2Fdm_events&oauth_version%3D1.0',
            $base
        );
    }

    // ─── send_twitter_dm ─────────────────────────────────────────────

    public function test_send_posts_a_signed_json_body_to_the_recipient_route(): void
    {
        $http = $this->http(
            $this->answer(
                201,
                array(
                    'data' => array(
                        'dm_conversation_id' => '1589490931234567890',
                        'dm_event_id'        => '1589490931234999999',
                    ),
                )
            )
        );

        $result = (new SendTwitterDmTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('recipient_id' => self::RECIPIENT, 'text' => '  Hello  '),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'event_id'        => '1589490931234999999',
                'conversation_id' => '1589490931234567890',
                'recipient_id'    => self::RECIPIENT,
                'text'            => 'Hello',
            ),
            $result['data']
        );

        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame(
            'https://api.twitter.com/2/dm_conversations/with/2244994945/messages',
            $this->requests[0]['url']
        );
        $this->assertSame(array('text' => 'Hello'), $this->body(0));
        $this->assertSame('application/json', $this->requests[0]['headers']['Content-Type']);
    }

    public function test_send_signs_with_oauth1_and_never_echoes_a_secret(): void
    {
        $http = $this->http($this->answer(201, array('data' => array())));

        (new SendTwitterDmTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('recipient_id' => self::RECIPIENT, 'text' => 'Hello'),
            self::CONTEXT
        );

        $authorization = $this->requests[0]['headers']['Authorization'];

        $this->assertStringStartsWith('OAuth ', $authorization);
        $this->assertStringContainsString('oauth_signature_method="HMAC-SHA1"', $authorization);
        $this->assertStringContainsString('oauth_consumer_key="consumer-key-3nR4"', $authorization);
        $this->assertStringContainsString('oauth_signature="', $authorization);
        $this->assertStringNotContainsString(self::CREDENTIALS['api_secret'], $authorization);
        $this->assertStringNotContainsString(self::CREDENTIALS['access_token_secret'], $authorization);
    }

    public function test_send_refuses_a_username_where_a_numeric_id_belongs(): void
    {
        $http = $this->http();

        $result = (new SendTwitterDmTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('recipient_id' => '@jack', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('recipient_id', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_send_refuses_a_path_traversal_in_the_recipient(): void
    {
        $http = $this->http();

        $result = (new SendTwitterDmTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('recipient_id' => '1/../../2/webhooks', 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_send_refuses_text_over_the_documented_ceiling(): void
    {
        $http = $this->http();

        $result = (new SendTwitterDmTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('recipient_id' => self::RECIPIENT, 'text' => str_repeat('a', 10001)),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('text', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_send_refuses_empty_text_without_a_request(): void
    {
        $http = $this->http();

        $result = (new SendTwitterDmTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('recipient_id' => self::RECIPIENT, 'text' => '   '),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_send_names_every_missing_credential_at_once(): void
    {
        $http = $this->http();

        $result = (new SendTwitterDmTool($this->errors, $http))->execute(
            array(
                'recipient_id' => self::RECIPIENT,
                'text'         => 'Hello',
                'api_key'      => 'only-one-supplied',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('api_secret', $result['error']['data']);
        $this->assertArrayHasKey('access_token', $result['error']['data']);
        $this->assertArrayHasKey('access_token_secret', $result['error']['data']);
        $this->assertArrayNotHasKey('api_key', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_send_classifies_a_problem_document_by_its_detail(): void
    {
        $http = $this->http(
            $this->answer(
                403,
                array(
                    'title'  => 'Forbidden',
                    'type'   => 'about:blank',
                    'status' => 403,
                    'detail' => 'You are not allowed to send messages to this user.',
                )
            )
        );

        $result = (new SendTwitterDmTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('recipient_id' => self::RECIPIENT, 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('twitter_api_error', $result['error']['code']);
        $this->assertStringContainsString(
            'You are not allowed to send messages to this user.',
            $result['error']['message']
        );
        $this->assertSame(403, $result['error']['data']['status']);
        $this->assertSame('403', $result['error']['data']['code']);
    }

    public function test_send_reports_the_rate_limit_reset_as_seconds_to_wait(): void
    {
        $reset = time() + 30;

        $http = $this->http(
            new HttpResponse(
                429,
                (string) json_encode(
                    array('title' => 'Too Many Requests', 'status' => 429, 'detail' => 'Too Many Requests')
                ),
                array('x-rate-limit-reset' => (string) $reset)
            )
        );

        $result = (new SendTwitterDmTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('recipient_id' => self::RECIPIENT, 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('twitter_api_error', $result['error']['code']);
        $this->assertGreaterThanOrEqual(28, $result['error']['data']['retry_after']);
        $this->assertLessThanOrEqual(30, $result['error']['data']['retry_after']);
    }

    public function test_send_classifies_a_transport_failure(): void
    {
        $http = $this->http(new \RuntimeException('cURL error 6: Could not resolve host'));

        $result = (new SendTwitterDmTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('recipient_id' => self::RECIPIENT, 'text' => 'Hello'),
            self::CONTEXT
        );

        $this->assertSame('chat_channel_request_failed', $result['error']['code']);
    }

    // ─── get_twitter_dms ─────────────────────────────────────────────

    public function test_get_reads_the_account_feed_and_projects_the_page(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'data' => array(
                        array(
                            'id'              => '1589490931234999999',
                            'event_type'      => 'MessageCreate',
                            'text'            => 'Hi there',
                            'created_at'      => '2026-01-02T03:04:05.000Z',
                            'sender_id'       => self::RECIPIENT,
                            'dm_conversation_id' => self::CONVERSATION,
                            'attachments'     => array('not in the contract'),
                        ),
                    ),
                    'meta' => array(
                        'result_count'    => 1,
                        'next_token'      => 'abc123def456ghi789jkl',
                        'previous_token'  => '',
                    ),
                )
            )
        );

        $result = (new GetTwitterDmsTool($this->errors, $http))->execute(
            array('bearer_token' => 'user-token'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                array(
                    'id'              => '1589490931234999999',
                    'event_type'      => 'MessageCreate',
                    'text'            => 'Hi there',
                    'created_at'      => '2026-01-02T03:04:05.000Z',
                    'sender_id'       => self::RECIPIENT,
                    'conversation_id' => self::CONVERSATION,
                ),
            ),
            $result['data']['events']
        );
        $this->assertSame('abc123def456ghi789jkl', $result['data']['next_token']);
        $this->assertSame(1, $result['data']['result_count']);
        $this->assertSame('', $result['data']['conversation_id']);

        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame('https://api.twitter.com/2/dm_events', strtok($this->requests[0]['url'], '?'));
        $this->assertSame(
            array('max_results' => '25', 'event_types' => 'MessageCreate'),
            $this->query(0)
        );
        $this->assertSame('Bearer user-token', $this->requests[0]['headers']['Authorization']);
    }

    public function test_get_routes_to_the_conversation_scoped_path(): void
    {
        $http = $this->http($this->answer(200, array('data' => array(), 'meta' => array('result_count' => 0))));

        (new GetTwitterDmsTool($this->errors, $http))->execute(
            array(
                'bearer_token'       => 'user-token',
                'dm_conversation_id' => self::CONVERSATION,
                'pagination_token'   => 'abc123def456ghi789jkl',
                'max_results'        => 50,
            ),
            self::CONTEXT
        );

        $this->assertSame(
            'https://api.twitter.com/2/dm_conversations/' . self::CONVERSATION . '/dm_events',
            strtok($this->requests[0]['url'], '?')
        );
        $this->assertSame(
            array(
                'max_results'      => '50',
                'event_types'      => 'MessageCreate',
                'pagination_token' => 'abc123def456ghi789jkl',
            ),
            $this->query(0)
        );
    }

    public function test_get_clamps_the_page_size_to_the_documented_ceiling(): void
    {
        $http = $this->http($this->answer(200, array('data' => array())));

        (new GetTwitterDmsTool($this->errors, $http))->execute(
            array('bearer_token' => 'user-token', 'max_results' => 500),
            self::CONTEXT
        );

        $this->assertSame('100', $this->query(0)['max_results']);
    }

    public function test_get_refuses_a_conversation_id_that_would_leave_the_path(): void
    {
        $http = $this->http();

        $result = (new GetTwitterDmsTool($this->errors, $http))->execute(
            array('bearer_token' => 'user-token', 'dm_conversation_id' => '../dm_events'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('dm_conversation_id', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_get_refuses_a_pagination_token_shorter_than_the_documented_floor(): void
    {
        $http = $this->http();

        $result = (new GetTwitterDmsTool($this->errors, $http))->execute(
            array('bearer_token' => 'user-token', 'pagination_token' => '1589490931234'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('pagination_token', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_get_refuses_a_missing_user_token_without_a_request(): void
    {
        $http = $this->http();

        $result = (new GetTwitterDmsTool($this->errors, $http))->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('bearer_token', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_get_classifies_a_401_by_status(): void
    {
        $http = $this->http(
            $this->answer(
                401,
                array(
                    'title'  => 'Unauthorized',
                    'type'   => 'about:blank',
                    'status' => 401,
                    'detail' => 'Unauthorized',
                )
            )
        );

        $result = (new GetTwitterDmsTool($this->errors, $http))->execute(
            array('bearer_token' => 'user-token'),
            self::CONTEXT
        );

        $this->assertSame('twitter_api_error', $result['error']['code']);
        $this->assertSame(401, $result['error']['data']['status']);
        $this->assertSame('Twitter API error: Unauthorized', $result['error']['message']);
    }

    // ─── manage_twitter_webhook ──────────────────────────────────────

    public function test_webhook_register_posts_to_the_environment_route(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'id'                => '1234567890',
                    'url'               => 'https://example.com/hook',
                    'valid'             => true,
                    'created_timestamp' => '2026-01-02T03:04:05Z',
                )
            )
        );

        $result = (new ManageTwitterWebhookTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'action'   => 'register',
                'env_name' => 'production',
                'url'      => 'https://example.com/hook',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'id'         => '1234567890',
                'url'        => 'https://example.com/hook',
                'valid'      => true,
                'created_at' => '2026-01-02T03:04:05Z',
            ),
            $result['data']['webhook']
        );
        $this->assertSame('production', $result['data']['environment']);

        $this->assertSame(
            'https://api.twitter.com/1.1/account_activity/all/production/webhooks.json',
            $this->requests[0]['url']
        );
        $this->assertSame(array('url' => 'https://example.com/hook'), $this->body(0));
    }

    public function test_webhook_refuses_a_missing_environment_name(): void
    {
        $http = $this->http();

        $result = (new ManageTwitterWebhookTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('action' => 'list'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('env_name', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_webhook_refuses_a_loopback_url(): void
    {
        $http = $this->http();

        $result = (new ManageTwitterWebhookTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'action'   => 'register',
                'env_name' => 'production',
                'url'      => 'https://127.0.0.1/hook',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('url', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_webhook_refuses_a_url_carrying_userinfo(): void
    {
        $http = $this->http();

        $result = (new ManageTwitterWebhookTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'action'   => 'register',
                'env_name' => 'production',
                'url'      => 'https://user:pass@example.com/hook',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_webhook_delete_requires_an_id_and_uses_the_dotted_route(): void
    {
        $http = $this->http($this->answer(204, array()), $this->answer(204, array()));

        $tool = new ManageTwitterWebhookTool($this->errors, $http);

        $refused = $tool->execute(
            self::CREDENTIALS + array('action' => 'delete', 'env_name' => 'production'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $refused['error']['code']);
        $this->assertArrayHasKey('webhook_id', $refused['error']['data']);
        $this->assertSame(array(), $this->requests);

        $deleted = $tool->execute(
            self::CREDENTIALS + array(
                'action'     => 'delete',
                'env_name'   => 'production',
                'webhook_id' => '1234567890',
            ),
            self::CONTEXT
        );

        $this->assertTrue($deleted['success']);
        $this->assertTrue($deleted['data']['deleted']);
        $this->assertSame('DELETE', $this->requests[0]['method']);
        $this->assertSame(
            'https://api.twitter.com/1.1/account_activity/all/production/webhooks/1234567890.json',
            $this->requests[0]['url']
        );
        $this->assertNull($this->requests[0]['body']);
    }

    public function test_webhook_subscribe_needs_no_webhook_id(): void
    {
        $http = $this->http($this->answer(204, array()));

        $result = (new ManageTwitterWebhookTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('action' => 'subscribe', 'env_name' => 'production'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['subscribed']);
        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame(
            'https://api.twitter.com/1.1/account_activity/all/production/subscriptions.json',
            $this->requests[0]['url']
        );
    }

    public function test_webhook_list_flattens_the_environment_wrapper(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'environments' => array(
                        array(
                            'environment_name' => 'production',
                            'webhooks'         => array(
                                array(
                                    'id'                => '1234567890',
                                    'url'               => 'https://example.com/hook',
                                    'valid'             => false,
                                    'created_timestamp' => '2026-01-02T03:04:05Z',
                                ),
                            ),
                        ),
                        array('environment_name' => 'staging', 'webhooks' => array()),
                    ),
                )
            )
        );

        $result = (new ManageTwitterWebhookTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('action' => 'list', 'env_name' => 'production'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['count']);
        $this->assertSame(
            array(
                array(
                    'id'         => '1234567890',
                    'url'        => 'https://example.com/hook',
                    'valid'      => false,
                    'created_at' => '2026-01-02T03:04:05Z',
                ),
            ),
            $result['data']['webhooks']
        );
    }

    public function test_webhook_refuses_an_unsupported_action_without_a_request(): void
    {
        $http = $this->http();

        $result = (new ManageTwitterWebhookTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('action' => 'purge', 'env_name' => 'production'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('action', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_webhook_classifies_a_missing_environment(): void
    {
        $http = $this->http(
            $this->answer(
                404,
                array(
                    'errors' => array(
                        array('code' => 34, 'message' => 'Sorry, that page does not exist.'),
                    ),
                )
            )
        );

        $result = (new ManageTwitterWebhookTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('action' => 'list', 'env_name' => 'nosuchenv'),
            self::CONTEXT
        );

        $this->assertSame('twitter_api_error', $result['error']['code']);
        $this->assertSame(404, $result['error']['data']['status']);
        $this->assertSame('34', $result['error']['data']['code']);
        $this->assertStringContainsString('that page does not exist', $result['error']['message']);
    }

    // ─── helpers ─────────────────────────────────────────────────────

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
