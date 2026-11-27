<?php

/**
 * The seven gateway tools: four Apple Messages for Business, three iCloud Drive.
 *
 * These tools do not talk to a first-party API. Apple publishes the REST API its
 * Messaging Service Providers implement, and has no public drive API at all, so
 * both families post to an endpoint the caller names and carry a bearer token
 * there. Three things follow, and all three are pinned here.
 *
 * First, the endpoint is a credential route: a loopback or credentialed URL must
 * be refused before any request, for every one of the seven.
 *
 * Second, the Apple tools had a habit of **skipping or truncating** what they
 * could not accept — an over-long body sliced at 2,000 characters, an eleventh
 * picker item dropped, a malformed section `continue`d past. Those are refusals
 * now, because a caller's widget silently losing content is worse than an error.
 *
 * Third, the response schema belongs to the caller's gateway, so a success
 * carries the decoded document under `response` rather than a guessed
 * projection, with a conventional collection also surfaced as `items`.
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
use Nvoos\Core\Tool\ChatChannels\GetAppleMessagesTool;
use Nvoos\Core\Tool\ChatChannels\GetICloudDriveFileTool;
use Nvoos\Core\Tool\ChatChannels\ListICloudDriveFilesTool;
use Nvoos\Core\Tool\ChatChannels\SendAppleMessageGroupTool;
use Nvoos\Core\Tool\ChatChannels\SendAppleMessageInteractiveTool;
use Nvoos\Core\Tool\ChatChannels\SendAppleMessageTool;
use Nvoos\Core\Tool\ChatChannels\UploadICloudDriveFileTool;
use PHPUnit\Framework\TestCase;

final class ChatChannelGatewayToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const MSP = 'https://msp.example.com/v1/apple/messages';

    private const GATEWAY = 'https://icloud.example.com/v1/drive';

    /**
     * @var array<string, string>
     */
    private const CREDENTIALS = array('api_key' => 'msp-token-Zq7Lm2');

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

    // ─── send_apple_message ──────────────────────────────────────────

    public function test_send_posts_the_family_envelope_and_projects_the_ids(): void
    {
        $http = $this->http($this->answer(201, array('messageId' => 'mid-1', 'status' => 'accepted')));

        $result = (new SendAppleMessageTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'     => self::MSP,
                'business_id'     => 'biz-42',
                'conversation_id' => 'conv-9',
                'message'         => '  Hello  ',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('mid-1', $result['data']['message_id']);
        $this->assertSame('conv-9', $result['data']['conversation_id']);
        $this->assertSame(5, $result['data']['text_length']);
        $this->assertSame(array('messageId' => 'mid-1', 'status' => 'accepted'), $result['data']['response']);

        $this->assertSame('POST', $this->requests[0]['method']);
        $this->assertSame(self::MSP, $this->requests[0]['url']);
        $this->assertSame('Bearer msp-token-Zq7Lm2', $this->requests[0]['headers']['Authorization']);
        $this->assertSame('application/json', $this->requests[0]['headers']['Content-Type']);

        $body = $this->body(0);

        $this->assertSame('biz-42', $body['businessId']);
        $this->assertSame('text', $body['type']);
        $this->assertSame('Hello', $body['body']['text']);
        $this->assertSame('en-US', $body['locale']);
        $this->assertSame('conv-9', $body['conversationId']);
        $this->assertArrayNotHasKey('recipientId', $body);
    }

    public function test_send_carries_the_intent_when_given(): void
    {
        $http = $this->http($this->answer(200, array()));

        (new SendAppleMessageTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'  => self::MSP,
                'business_id'  => 'biz-42',
                'recipient_id' => 'cust-1',
                'message'      => 'Hello',
                'intent'       => 'billing',
                'locale'       => 'fr-CA',
            ),
            self::CONTEXT
        );

        $body = $this->body(0);

        $this->assertSame('billing', $body['intent']);
        $this->assertSame('fr-CA', $body['locale']);
        $this->assertSame('cust-1', $body['recipientId']);
    }

    public function test_send_refuses_a_loopback_provider_endpoint(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'     => 'https://127.0.0.1/v1/apple',
                'business_id'     => 'biz-42',
                'conversation_id' => 'conv-9',
                'message'         => 'Hello',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('msp_api_url', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_send_refuses_an_over_long_body_rather_than_truncating_it(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'     => self::MSP,
                'business_id'     => 'biz-42',
                'conversation_id' => 'conv-9',
                'message'         => str_repeat('a', 2001),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('message', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_send_refuses_a_message_with_no_addressee(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url' => self::MSP,
                'business_id' => 'biz-42',
                'message'     => 'Hello',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('conversation_id', $result['error']['data']);
        $this->assertArrayHasKey('recipient_id', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_send_refuses_a_malformed_locale(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'     => self::MSP,
                'business_id'     => 'biz-42',
                'conversation_id' => 'conv-9',
                'message'         => 'Hello',
                'locale'          => 'english please',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('locale', $result['error']['data']);
    }

    public function test_send_classifies_a_gateway_error_with_its_status(): void
    {
        $http = $this->http(
            $this->answer(422, array('message' => 'Recipient opted out.', 'code' => 'opt_out'))
        );

        $result = (new SendAppleMessageTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'     => self::MSP,
                'business_id'     => 'biz-42',
                'conversation_id' => 'conv-9',
                'message'         => 'Hello',
            ),
            self::CONTEXT
        );

        $this->assertSame('gateway_api_error', $result['error']['code']);
        $this->assertSame(422, $result['error']['data']['status']);
        $this->assertSame('opt_out', $result['error']['data']['code']);
        $this->assertStringContainsString('Recipient opted out.', $result['error']['message']);
    }

    public function test_send_refuses_a_missing_api_key_without_a_request(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageTool($this->errors, $http))->execute(
            array(
                'msp_api_url'     => self::MSP,
                'business_id'     => 'biz-42',
                'conversation_id' => 'conv-9',
                'message'         => 'Hello',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('api_key', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    // ─── send_apple_message_group ───────────────────────────────────

    public function test_group_posts_the_group_envelope(): void
    {
        $http = $this->http($this->answer(201, array('id' => 'grp-7')));

        $result = (new SendAppleMessageGroupTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'  => self::MSP,
                'business_id'  => 'biz-42',
                'group_name'   => 'Billing',
                'participants' => array('p-1', 'p-2'),
                'sender_name'  => 'Agent Smith',
                'message'      => 'Hello group',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['participants']);
        $this->assertSame('grp-7', $result['data']['message_id']);

        $body = $this->body(0);

        $this->assertSame('group', $body['type']);
        $this->assertSame('Billing', $body['groupName']);
        $this->assertSame(array('p-1', 'p-2'), $body['participants']);
        $this->assertSame('Agent Smith', $body['senderName']);
        $this->assertArrayNotHasKey('groupId', $body);
    }

    public function test_group_refuses_more_than_thirty_two_participants(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageGroupTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'  => self::MSP,
                'business_id'  => 'biz-42',
                'participants' => array_map(static fn (int $i): string => 'p-' . $i, range(1, 33)),
                'message'      => 'Hello group',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('participants', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_group_refuses_a_malformed_participant_instead_of_dropping_it(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageGroupTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'  => self::MSP,
                'business_id'  => 'biz-42',
                'participants' => array('p-1', array('not' => 'an id')),
                'message'      => 'Hello group',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('participants', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_group_refuses_a_group_with_neither_id_nor_participants(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageGroupTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url' => self::MSP,
                'business_id' => 'biz-42',
                'group_name'  => 'Nobody',
                'message'     => 'Hello group',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('group_id', $result['error']['data']);
        $this->assertArrayHasKey('participants', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    // ─── send_apple_message_interactive ─────────────────────────────

    public function test_list_picker_builds_sections_and_reads_multiple_selection_as_a_boolean(): void
    {
        $http = $this->http($this->answer(201, array('messageId' => 'mid-2')));

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'          => self::MSP,
                'business_id'          => 'biz-42',
                'conversation_id'      => 'conv-9',
                'interactive_type'     => 'list_picker',
                'body_text'            => 'Pick a plan',
                'list_picker_sections' => array(
                    array(
                        'title'             => 'Plans',
                        'multipleSelection' => 'false',
                        'items'             => array(
                            array('identifier' => 'basic', 'title' => 'Basic', 'style' => 'default'),
                            array(
                                'identifier' => 'pro',
                                'title'      => 'Pro',
                                'subtitle'   => 'Everything',
                                'imageData'  => 'https://cdn.example.com/pro.png',
                            ),
                        ),
                    ),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('list_picker', $result['data']['interactive_type']);

        $body = $this->body(0);

        $this->assertSame('interactive', $body['type']);
        $this->assertSame('list_picker', $body['interactiveType']);
        $this->assertSame('Pick a plan', $body['body']['text']);

        $section = $body['interactive']['sections'][0];

        $this->assertSame('Plans', $section['title']);
        $this->assertFalse($section['multipleSelection']);
        $this->assertCount(2, $section['items']);
        $this->assertSame('https://cdn.example.com/pro.png', $section['items'][1]['imageData']);
    }

    public function test_list_picker_refuses_an_eleventh_item_rather_than_slicing(): void
    {
        $http = $this->http();

        $items = array();

        for ($i = 1; $i <= 11; $i++) {
            $items[] = array('identifier' => 'i-' . $i, 'title' => 'Item ' . $i);
        }

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'          => self::MSP,
                'business_id'          => 'biz-42',
                'conversation_id'      => 'conv-9',
                'interactive_type'     => 'list_picker',
                'body_text'            => 'Pick',
                'list_picker_sections' => array(array('items' => $items)),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('10 items', $result['error']['message']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_list_picker_refuses_an_item_without_a_title_rather_than_skipping_it(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'          => self::MSP,
                'business_id'          => 'biz-42',
                'conversation_id'      => 'conv-9',
                'interactive_type'     => 'list_picker',
                'body_text'            => 'Pick',
                'list_picker_sections' => array(
                    array('items' => array(array('identifier' => 'basic'))),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_list_picker_accepts_a_base64_data_image(): void
    {
        $http = $this->http($this->answer(201, array()));

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'          => self::MSP,
                'business_id'          => 'biz-42',
                'conversation_id'      => 'conv-9',
                'interactive_type'     => 'list_picker',
                'body_text'            => 'Pick',
                'list_picker_sections' => array(
                    array(
                        'items' => array(
                            array(
                                'identifier' => 'basic',
                                'title'      => 'Basic',
                                'imageData'  => 'data:image/png;base64,iVBORw0KGgo=',
                            ),
                        ),
                    ),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            'data:image/png;base64,iVBORw0KGgo=',
            $this->body(0)['interactive']['sections'][0]['items'][0]['imageData']
        );
    }

    public function test_list_picker_refuses_a_data_uri_that_is_not_base64(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'          => self::MSP,
                'business_id'          => 'biz-42',
                'conversation_id'      => 'conv-9',
                'interactive_type'     => 'list_picker',
                'body_text'            => 'Pick',
                'list_picker_sections' => array(
                    array(
                        'items' => array(
                            array(
                                'identifier' => 'basic',
                                'title'      => 'Basic',
                                'imageData'  => 'data:image/png;base64,not base64!',
                            ),
                        ),
                    ),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_time_picker_builds_the_event_with_location_and_slots(): void
    {
        $http = $this->http($this->answer(201, array()));

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'        => self::MSP,
                'business_id'        => 'biz-42',
                'conversation_id'    => 'conv-9',
                'interactive_type'   => 'time_picker',
                'body_text'          => 'Pick a time',
                'time_picker_event'  => array(
                    'identifier' => 'evt-1',
                    'title'      => 'Consultation',
                    'location'   => array(
                        'title'     => 'HQ',
                        'latitude'  => 6.8,
                        'longitude' => -58.1,
                        'radius'    => 150,
                    ),
                    'timeslots'  => array(
                        array('identifier' => 's-1', 'startTime' => '2026-06-01T09:00:00Z', 'duration' => 1800),
                    ),
                ),
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);

        $event = $this->body(0)['interactive']['event'];

        $this->assertSame('evt-1', $event['identifier']);
        $this->assertSame('Consultation', $event['title']);
        $this->assertSame(6.8, $event['location']['latitude']);
        $this->assertSame(-58.1, $event['location']['longitude']);
        // JSON has one number type, so an integral float round-trips as an int.
        $this->assertSame(150.0, (float) $event['location']['radius']);
        $this->assertSame('2026-06-01T09:00:00Z', $event['timeslots'][0]['startTime']);
        $this->assertSame(1800, $event['timeslots'][0]['duration']);
    }

    public function test_time_picker_refuses_a_start_time_that_is_not_an_instant(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'       => self::MSP,
                'business_id'       => 'biz-42',
                'conversation_id'   => 'conv-9',
                'interactive_type'  => 'time_picker',
                'body_text'         => 'Pick a time',
                'time_picker_event' => array(
                    'identifier' => 'evt-1',
                    'title'      => 'Consultation',
                    'timeslots'  => array(array('identifier' => 's-1', 'startTime' => 'next tuesday')),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('ISO 8601', $result['error']['message']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_time_picker_refuses_an_out_of_range_latitude(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'       => self::MSP,
                'business_id'       => 'biz-42',
                'conversation_id'   => 'conv-9',
                'interactive_type'  => 'time_picker',
                'body_text'         => 'Pick a time',
                'time_picker_event' => array(
                    'identifier' => 'evt-1',
                    'title'      => 'Consultation',
                    'location'   => array('latitude' => 91),
                ),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_rich_link_refuses_an_http_destination(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'      => self::MSP,
                'business_id'      => 'biz-42',
                'conversation_id'  => 'conv-9',
                'interactive_type' => 'rich_link',
                'body_text'        => 'Look at this',
                'rich_link_url'    => 'http://example.com/offer',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('rich_link_url', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_rich_link_builds_the_card(): void
    {
        $http = $this->http($this->answer(201, array()));

        (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'         => self::MSP,
                'business_id'         => 'biz-42',
                'conversation_id'     => 'conv-9',
                'interactive_type'    => 'rich_link',
                'body_text'           => 'Look at this',
                'rich_link_url'       => 'https://example.com/offer',
                'rich_link_title'     => 'Spring offer',
                'rich_link_image_url' => 'https://cdn.example.com/spring.png',
            ),
            self::CONTEXT
        );

        $link = $this->body(0)['interactive']['richLink'];

        $this->assertSame('https://example.com/offer', $link['url']);
        $this->assertSame('Spring offer', $link['title']);
        $this->assertSame('https://cdn.example.com/spring.png', $link['imageUrl']);
    }

    public function test_authenticate_requires_a_request_id(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'      => self::MSP,
                'business_id'      => 'biz-42',
                'conversation_id'  => 'conv-9',
                'interactive_type' => 'authenticate',
                'body_text'        => 'Please sign in',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('authenticate_request_id', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_interactive_refuses_an_unknown_type(): void
    {
        $http = $this->http();

        $result = (new SendAppleMessageInteractiveTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'      => self::MSP,
                'business_id'      => 'biz-42',
                'conversation_id'  => 'conv-9',
                'interactive_type' => 'carousel',
                'body_text'        => 'Pick',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('interactive_type', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    // ─── get_apple_messages ─────────────────────────────────────────

    public function test_get_sends_the_documented_defaults(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array('data' => array(array('id' => 'm-1', 'text' => 'Hi'), array('id' => 'm-2')))
            )
        );

        $result = (new GetAppleMessagesTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('msp_api_url' => self::MSP, 'business_id' => 'biz-42'),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame('m-1', $result['data']['items'][0]['id']);

        $this->assertSame('GET', $this->requests[0]['method']);
        $this->assertSame(
            array('businessId' => 'biz-42', 'limit' => '25', 'status' => 'open'),
            $this->query(0)
        );
    }

    public function test_get_carries_the_conversation_and_cursors(): void
    {
        $http = $this->http($this->answer(200, array('conversations' => array())));

        (new GetAppleMessagesTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url'     => self::MSP,
                'business_id'     => 'biz-42',
                'conversation_id' => 'conv-9',
                'limit'           => 500,
                'after'           => 'cursor-abc',
                'status'          => 'all',
            ),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                'businessId'     => 'biz-42',
                'limit'          => '100',
                'status'         => 'all',
                'conversationId' => 'conv-9',
                'after'          => 'cursor-abc',
            ),
            $this->query(0)
        );
    }

    public function test_get_refuses_an_unknown_status(): void
    {
        $http = $this->http();

        $result = (new GetAppleMessagesTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'msp_api_url' => self::MSP,
                'business_id' => 'biz-42',
                'status'      => 'archived',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('status', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    // ─── iCloud Drive ───────────────────────────────────────────────

    public function test_list_sends_the_page_and_returns_items(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array('files' => array(array('id' => 'f-1', 'name' => 'notes.txt'), array('id' => 'f-2')))
            )
        );

        $result = (new ListICloudDriveFilesTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('gateway_url' => self::GATEWAY, 'folder_id' => 'root-1', 'limit' => 10),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame('root-1', $result['data']['folder_id']);
        $this->assertSame(
            array('limit' => '10', 'folderId' => 'root-1'),
            $this->query(0)
        );
    }

    public function test_list_refuses_a_non_public_gateway(): void
    {
        $http = $this->http();

        $result = (new ListICloudDriveFilesTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('gateway_url' => 'https://localhost/drive'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('gateway_url', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_get_requires_a_file_id_and_projects_nullable_metadata(): void
    {
        $http = $this->http($this->answer(200, array('name' => 'report.pdf', 'size' => 4096, 'extra' => 'kept')));

        $tool = new GetICloudDriveFileTool($this->errors, $http);

        $refused = $tool->execute(
            self::CREDENTIALS + array('gateway_url' => self::GATEWAY),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $refused['error']['code']);
        $this->assertArrayHasKey('file_id', $refused['error']['data']);
        $this->assertSame(array(), $this->requests);

        $found = $tool->execute(
            self::CREDENTIALS + array('gateway_url' => self::GATEWAY, 'file_id' => 'f-1'),
            self::CONTEXT
        );

        $this->assertTrue($found['success']);
        $this->assertSame('report.pdf', $found['data']['name']);
        $this->assertSame('4096', $found['data']['size']);
        $this->assertNull($found['data']['content_type']);
        $this->assertNull($found['data']['modified_at']);
        $this->assertSame('kept', $found['data']['response']['extra']);
        $this->assertSame(array('fileId' => 'f-1'), $this->query(0));
    }

    public function test_upload_posts_the_compact_base64_and_reports_the_decoded_size(): void
    {
        $http = $this->http($this->answer(201, array('fileId' => 'f-9')));

        $result = (new UploadICloudDriveFileTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'gateway_url'  => self::GATEWAY,
                'file_name'    => 'hello.txt',
                'content'      => "aGVs\nbG8=",
                'folder_id'    => 'folder-1',
                'content_type' => 'text/plain',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(5, $result['data']['bytes']);
        $this->assertSame('f-9', $result['data']['file_id']);

        $body = $this->body(0);

        $this->assertSame('hello.txt', $body['fileName']);
        $this->assertSame('aGVsbG8=', $body['content']);
        $this->assertSame('text/plain', $body['contentType']);
        $this->assertSame('folder-1', $body['folderId']);
    }

    public function test_upload_refuses_a_file_name_that_is_a_path(): void
    {
        $http = $this->http();

        $result = (new UploadICloudDriveFileTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'gateway_url' => self::GATEWAY,
                'file_name'   => '../../secrets.txt',
                'content'     => 'aGVsbG8=',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('file_name', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_upload_refuses_content_that_is_not_base64(): void
    {
        $http = $this->http();

        $result = (new UploadICloudDriveFileTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'gateway_url' => self::GATEWAY,
                'file_name'   => 'hello.txt',
                'content'     => '!!!not base64!!!',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('content', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_upload_refuses_a_bad_content_type(): void
    {
        $http = $this->http();

        $result = (new UploadICloudDriveFileTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'gateway_url'  => self::GATEWAY,
                'file_name'    => 'hello.txt',
                'content'      => 'aGVsbG8=',
                'content_type' => 'plain text',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('content_type', $result['error']['data']);
        $this->assertSame(array(), $this->requests);
    }

    public function test_upload_defaults_the_content_type(): void
    {
        $http = $this->http($this->answer(201, array()));

        (new UploadICloudDriveFileTool($this->errors, $http))->execute(
            self::CREDENTIALS + array(
                'gateway_url' => self::GATEWAY,
                'file_name'   => 'blob.bin',
                'content'     => 'aGVsbG8=',
            ),
            self::CONTEXT
        );

        $this->assertSame('application/octet-stream', $this->body(0)['contentType']);
    }

    public function test_a_gateway_transport_failure_is_classified(): void
    {
        $http = $this->http(new \RuntimeException('cURL error 7: Failed to connect'));

        $result = (new ListICloudDriveFilesTool($this->errors, $http))->execute(
            self::CREDENTIALS + array('gateway_url' => self::GATEWAY),
            self::CONTEXT
        );

        $this->assertSame('chat_channel_request_failed', $result['error']['code']);
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
