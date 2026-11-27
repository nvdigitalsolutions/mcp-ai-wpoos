<?php

/**
 * The eight Microsoft Graph tools: the route each builds, the projection each
 * returns, the link guard, and the error classification.
 *
 * Three assertions matter most. First, every id that becomes a path segment is
 * validated, and a traversal shape never reaches the client — those tests queue
 * no answer, so a request would throw. Second, a `next_link` is only followed
 * when it is https and points at Graph itself: the link is fetched with the
 * caller's bearer token, so an arbitrary host would hand the token away. Third,
 * Graph's `error.code` is a word and is carried as the provider code.
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
use Nvoos\Core\Tool\ChatChannels\GetOnedriveFileTool;
use Nvoos\Core\Tool\ChatChannels\GetOutlookMessagesTool;
use Nvoos\Core\Tool\ChatChannels\GetTeamsChannelsTool;
use Nvoos\Core\Tool\ChatChannels\GetTeamsMessagesTool;
use Nvoos\Core\Tool\ChatChannels\ListOnedriveFilesTool;
use Nvoos\Core\Tool\ChatChannels\SendOutlookMailTool;
use Nvoos\Core\Tool\ChatChannels\SendTeamsMessageTool;
use Nvoos\Core\Tool\ChatChannels\UploadOnedriveFileTool;
use PHPUnit\Framework\TestCase;

final class ChatChannelGraphToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private const TOKEN = 'graph-secret';

    private const TEAM = '11111111-2222-3333-4444-555555555555';

    private const CHANNEL = '19:abc123@thread.tacv2';

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

    // ─── Teams: channels ─────────────────────────────────────────────

    public function test_channels_projects_rows_and_the_next_link(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'value'            => array(
                        array(
                            'id'             => self::CHANNEL,
                            'displayName'    => 'General',
                            'description'    => 'Team-wide chat',
                            'membershipType' => 'standard',
                            'webUrl'         => 'https://teams.example/1',
                            'isFavoriteByDefault' => 'not in the contract',
                        ),
                        'not-a-row',
                    ),
                    '@odata.nextLink'  => 'https://graph.microsoft.com/v1.0/teams/' . self::TEAM . '/channels?$skiptoken=next',
                )
            )
        );

        $result = (new GetTeamsChannelsTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                array(
                    'id'              => self::CHANNEL,
                    'display_name'    => 'General',
                    'description'     => 'Team-wide chat',
                    'membership_type' => 'standard',
                    'web_url'         => 'https://teams.example/1',
                ),
            ),
            $result['data']['channels']
        );
        $this->assertSame(1, $result['data']['count']);
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/teams/' . self::TEAM . '/channels?$skiptoken=next',
            $result['data']['next_link']
        );
        $this->assertSame(
            'https://graph.microsoft.com/v1.0/teams/' . self::TEAM . '/channels',
            $this->requests[0]['url']
        );
        $this->assertSame('Bearer ' . self::TOKEN, $this->requests[0]['headers']['Authorization']);
    }

    public function test_channels_refuses_a_team_id_that_is_not_a_guid(): void
    {
        $http = $this->http();

        $result = (new GetTeamsChannelsTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => '../../me'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('team_id', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_channels_refuses_a_next_link_on_another_host_without_a_request(): void
    {
        $http = $this->http();

        $result = (new GetTeamsChannelsTool($this->errors, $http))->execute(
            array(
                'token'     => self::TOKEN,
                'team_id'   => self::TEAM,
                'next_link' => 'https://evil.example/v1.0/steal',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('next_link', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_channels_follows_a_valid_next_link_as_given(): void
    {
        $link = 'https://graph.microsoft.com/v1.0/teams/' . self::TEAM . '/channels?$skiptoken=abc';

        $http = $this->http($this->answer(200, array('value' => array())));

        $result = (new GetTeamsChannelsTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM, 'next_link' => $link),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame($link, $this->requests[0]['url']);
        $this->assertNull($result['data']['next_link']);
    }

    // ─── Teams: messages ─────────────────────────────────────────────

    public function test_messages_projects_rows_and_sends_the_top(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'value' => array(
                        array(
                            'id'              => 'm1',
                            'replyToId'       => 'm0',
                            'messageType'     => 'message',
                            'subject'         => 'Deploy',
                            'body'            => array('content' => 'Shipped', 'contentType' => 'text'),
                            'from'            => array('user' => array('displayName' => 'Alice')),
                            'createdDateTime' => '2026-11-27T10:00:00Z',
                            'webUrl'          => 'https://teams.example/m1',
                            'deletedDateTime' => null,
                            'reactions'       => array('not in the contract'),
                        ),
                        array(
                            'id'   => 'm2',
                            'from' => array('application' => array('displayName' => 'Bot')),
                        ),
                    ),
                )
            )
        );

        $result = (new GetTeamsMessagesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM, 'channel_id' => self::CHANNEL, 'top' => 10),
            self::CONTEXT
        );

        $this->assertSame('Alice', $result['data']['messages'][0]['from']);
        $this->assertSame('Shipped', $result['data']['messages'][0]['content']);
        $this->assertSame('Bot', $result['data']['messages'][1]['from']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame(10, $result['data']['top']);
        $this->assertStringContainsString('19%3Aabc123%40thread.tacv2', $this->requests[0]['url']);
        $this->assertSame('10', $this->query(0)['$top']);
    }

    public function test_messages_clamps_the_top(): void
    {
        $http = $this->http($this->answer(200, array('value' => array())), $this->answer(200, array('value' => array())));

        (new GetTeamsMessagesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM, 'channel_id' => self::CHANNEL, 'top' => 500),
            self::CONTEXT
        );

        (new GetTeamsMessagesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM, 'channel_id' => self::CHANNEL),
            self::CONTEXT
        );

        $this->assertSame('50', $this->query(0)['$top']);
        $this->assertSame('50', $this->query(1)['$top']);
    }

    public function test_messages_refuses_a_channel_id_with_a_slash(): void
    {
        $http = $this->http();

        $result = (new GetTeamsMessagesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM, 'channel_id' => '19:x/messages'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('channel_id', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_send_posts_the_body_and_projects_the_created_message(): void
    {
        $http = $this->http(
            $this->answer(
                201,
                array(
                    'id'              => 'm9',
                    'createdDateTime' => '2026-11-27T10:05:00Z',
                    'webUrl'          => 'https://teams.example/m9',
                    'body'            => array('content' => 'Hi', 'contentType' => 'text'),
                )
            )
        );

        $result = (new SendTeamsMessageTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM, 'channel_id' => self::CHANNEL, 'content' => 'Hi'),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                'id'                => 'm9',
                'created_date_time' => '2026-11-27T10:05:00Z',
                'web_url'           => 'https://teams.example/m9',
            ),
            $result['data']
        );
        $this->assertSame(
            array('body' => array('content' => 'Hi')),
            $this->body(0)
        );
        $this->assertSame('POST', $this->requests[0]['method']);
    }

    // ─── Outlook ─────────────────────────────────────────────────────

    public function test_outlook_projects_rows_and_sends_the_filter(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'value' => array(
                        array(
                            'id'               => 'AAMk1',
                            'subject'          => 'Invoice',
                            'from'             => array('emailAddress' => array('address' => 'billing@example.com', 'name' => 'Billing')),
                            'receivedDateTime' => '2026-11-27T09:00:00Z',
                            'bodyPreview'      => 'Please find',
                            'isRead'           => false,
                            'hasAttachments'   => true,
                            'webLink'          => 'https://outlook.example/1',
                            'body'             => array('not in the contract'),
                        ),
                    ),
                )
            )
        );

        $result = (new GetOutlookMessagesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'top' => 5, 'filter' => 'isRead eq false'),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                array(
                    'id'                 => 'AAMk1',
                    'subject'            => 'Invoice',
                    'from_address'       => 'billing@example.com',
                    'from_name'          => 'Billing',
                    'received_date_time' => '2026-11-27T09:00:00Z',
                    'body_preview'       => 'Please find',
                    'is_read'            => false,
                    'has_attachments'    => true,
                    'web_link'           => 'https://outlook.example/1',
                ),
            ),
            $result['data']['messages']
        );
        $this->assertSame('inbox', $result['data']['folder_id']);
        $this->assertStringContainsString('/me/mailFolders/inbox/messages', $this->requests[0]['url']);
        $this->assertSame('5', $this->query(0)['$top']);
        $this->assertSame('isRead eq false', $this->query(0)['$filter']);
    }

    public function test_outlook_refuses_a_next_link_on_plain_http(): void
    {
        $http = $this->http();

        $result = (new GetOutlookMessagesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'next_link' => 'http://graph.microsoft.com/v1.0/me/messages'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_send_mail_posts_the_graph_message_and_reports_the_queue(): void
    {
        $http = $this->http($this->answer(202, array()));

        $result = (new SendOutlookMailTool($this->errors, $http))->execute(
            array(
                'token'    => self::TOKEN,
                'to_email' => 'alice@example.com',
                'subject'  => 'Report',
                'body'     => '<p>Attached</p>',
                'cc_email' => 'bob@example.com',
                'content_type' => 'html',
            ),
            self::CONTEXT
        );

        $this->assertSame(
            array(
                'sent'         => true,
                'to'           => 'alice@example.com',
                'cc'           => 'bob@example.com',
                'subject'      => 'Report',
                'content_type' => 'html',
            ),
            $result['data']
        );

        $body = $this->body(0);

        $this->assertSame('Report', $body['message']['subject']);
        $this->assertSame('HTML', $body['message']['body']['contentType']);
        $this->assertSame('alice@example.com', $body['message']['toRecipients'][0]['emailAddress']['address']);
        $this->assertSame('bob@example.com', $body['message']['ccRecipients'][0]['emailAddress']['address']);
        $this->assertSame('https://graph.microsoft.com/v1.0/me/sendMail', $this->requests[0]['url']);
    }

    public function test_send_mail_refuses_an_invalid_cc_without_a_request(): void
    {
        $http = $this->http();

        $result = (new SendOutlookMailTool($this->errors, $http))->execute(
            array(
                'token'    => self::TOKEN,
                'to_email' => 'alice@example.com',
                'subject'  => 'Report',
                'body'     => 'Body',
                'cc_email' => 'not-an-address',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('cc_email', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_send_mail_refuses_an_unsupported_content_type(): void
    {
        $http = $this->http();

        $result = (new SendOutlookMailTool($this->errors, $http))->execute(
            array(
                'token'        => self::TOKEN,
                'to_email'     => 'alice@example.com',
                'subject'      => 'Report',
                'body'         => 'Body',
                'content_type' => 'markdown',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('content_type', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    // ─── OneDrive ────────────────────────────────────────────────────

    public function test_list_root_projects_items(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'value' => array(
                        array(
                            'id'                   => '01FILE',
                            'name'                 => 'report.docx',
                            'size'                 => 1234,
                            'file'                 => array('mimeType' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'),
                            'webUrl'               => 'https://onedrive.example/1',
                            'lastModifiedDateTime' => '2026-11-27T08:00:00Z',
                        ),
                        array(
                            'id'     => '01FOLDER',
                            'name'   => 'Documents',
                            'size'   => 0,
                            'folder' => array('childCount' => 3),
                        ),
                    ),
                )
            )
        );

        $result = (new ListOnedriveFilesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN),
            self::CONTEXT
        );

        $this->assertSame('report.docx', $result['data']['files'][0]['name']);
        $this->assertFalse($result['data']['files'][0]['is_folder']);
        $this->assertNull($result['data']['files'][0]['child_count']);
        $this->assertTrue($result['data']['files'][1]['is_folder']);
        $this->assertSame(3, $result['data']['files'][1]['child_count']);
        $this->assertNull($result['data']['folder_path']);
        $this->assertStringContainsString('/me/drive/root/children', $this->requests[0]['url']);
        $this->assertSame('25', $this->query(0)['$top']);
    }

    public function test_list_subfolder_encodes_the_path(): void
    {
        $http = $this->http($this->answer(200, array('value' => array())));

        (new ListOnedriveFilesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'folder_path' => 'Documents/Reports 2026'),
            self::CONTEXT
        );

        $this->assertStringContainsString(
            '/me/drive/root:/Documents/Reports%202026:/children',
            $this->requests[0]['url']
        );
    }

    public function test_list_refuses_a_traversal_segment(): void
    {
        $http = $this->http();

        $result = (new ListOnedriveFilesTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'folder_path' => 'Documents/../../me'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_get_by_id_requests_the_item_route(): void
    {
        $http = $this->http(
            $this->answer(
                200,
                array(
                    'id'     => '01FILE!9',
                    'name'   => 'report.docx',
                    'size'   => 10,
                    'file'   => array('mimeType' => 'text/plain'),
                )
            )
        );

        $result = (new GetOnedriveFileTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'item_id' => '01FILE!9'),
            self::CONTEXT
        );

        $this->assertSame('report.docx', $result['data']['name']);
        $this->assertStringContainsString('/me/drive/items/01FILE%219', $this->requests[0]['url']);
    }

    public function test_get_by_path_requests_the_root_route(): void
    {
        $http = $this->http($this->answer(200, array('id' => '01FILE', 'name' => 'report.docx')));

        (new GetOnedriveFileTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'file_path' => 'Documents/report.docx'),
            self::CONTEXT
        );

        $this->assertStringEndsWith('/me/drive/root:/Documents/report.docx', $this->requests[0]['url']);
    }

    public function test_get_refuses_both_identifiers(): void
    {
        $http = $this->http();

        $result = (new GetOnedriveFileTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'item_id' => '01FILE', 'file_path' => 'Documents/report.docx'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_get_refuses_neither_identifier(): void
    {
        $http = $this->http();

        $result = (new GetOnedriveFileTool($this->errors, $http))->execute(
            array('token' => self::TOKEN),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    public function test_upload_puts_the_decoded_bytes_with_the_content_type(): void
    {
        $http = $this->http(
            $this->answer(
                201,
                array('id' => '01NEW', 'name' => 'hello.txt', 'size' => 5, 'file' => array('mimeType' => 'text/plain'))
            )
        );

        $result = (new UploadOnedriveFileTool($this->errors, $http))->execute(
            array(
                'token'        => self::TOKEN,
                'file_path'    => 'Documents/hello.txt',
                'content'      => base64_encode('hello'),
                'content_type' => 'text/plain',
            ),
            self::CONTEXT
        );

        $this->assertSame('hello.txt', $result['data']['name']);
        $this->assertSame(5, $result['data']['size']);
        $this->assertSame('PUT', $this->requests[0]['method']);
        $this->assertSame('hello', $this->requests[0]['body']);
        $this->assertSame('text/plain', $this->requests[0]['headers']['Content-Type']);
        $this->assertStringEndsWith('/me/drive/root:/Documents/hello.txt:/content', $this->requests[0]['url']);
    }

    public function test_upload_refuses_invalid_base64_without_a_request(): void
    {
        $http = $this->http();

        $result = (new UploadOnedriveFileTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'file_path' => 'Documents/hello.txt', 'content' => 'not base64!'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('content', $result['error']['data']);
        $this->assertCount(0, $this->requests);
    }

    public function test_upload_refuses_a_traversal_path(): void
    {
        $http = $this->http();

        $result = (new UploadOnedriveFileTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'file_path' => '../x.txt', 'content' => base64_encode('x')),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $this->requests);
    }

    // ─── Error classification ────────────────────────────────────────

    public function test_a_graph_error_carries_its_word_code(): void
    {
        $http = $this->http(
            $this->answer(
                403,
                array(
                    'error' => array(
                        'code'    => 'Forbidden',
                        'message' => 'Insufficient privileges to complete the operation.',
                    ),
                )
            )
        );

        $result = (new GetTeamsChannelsTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM),
            self::CONTEXT
        );

        $this->assertSame('graph_api_error', $result['error']['code']);
        $this->assertSame(403, $result['error']['data']['status']);
        $this->assertSame('Forbidden', $result['error']['data']['code']);
        $this->assertStringContainsString('Insufficient privileges', $result['error']['message']);
        $this->assertStringNotContainsString('graph-secret', (string) json_encode($result));
    }

    public function test_a_rate_limit_carries_the_retry_after_header(): void
    {
        $http = $this->http(
            new HttpResponse(
                429,
                (string) json_encode(
                    array('error' => array('code' => 'TooManyRequests', 'message' => 'Too many requests.'))
                ),
                array('Retry-After' => '12')
            )
        );

        $result = (new GetTeamsChannelsTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM),
            self::CONTEXT
        );

        $this->assertSame(429, $result['error']['data']['status']);
        $this->assertSame(12, $result['error']['data']['retry_after']);
    }

    public function test_a_transport_failure_is_one_envelope(): void
    {
        $http = $this->http(new \RuntimeException('Could not resolve host'));

        $result = (new GetTeamsChannelsTool($this->errors, $http))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM),
            self::CONTEXT
        );

        $this->assertSame('chat_channel_request_failed', $result['error']['code']);
        $this->assertStringContainsString('Could not resolve host', $result['error']['message']);
    }

    // ─── Family contract ─────────────────────────────────────────────

    public function test_reads_declare_read_only_and_writes_declare_write(): void
    {
        $http = $this->http();

        $reads = array(
            new GetTeamsChannelsTool($this->errors, $http),
            new GetTeamsMessagesTool($this->errors, $http),
            new GetOutlookMessagesTool($this->errors, $http),
            new GetOnedriveFileTool($this->errors, $http),
            new ListOnedriveFilesTool($this->errors, $http),
        );

        foreach ($reads as $tool) {
            $flags = $tool->getCapabilityFlags();

            $this->assertContains('read-only', $flags);
            $this->assertNotContains('write', $flags);
        }

        $writes = array(
            new SendTeamsMessageTool($this->errors, $http),
            new SendOutlookMailTool($this->errors, $http),
            new UploadOnedriveFileTool($this->errors, $http),
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
            (new GetTeamsChannelsTool($this->errors, $http))->getSlug(),
            (new GetTeamsMessagesTool($this->errors, $http))->getSlug(),
            (new SendTeamsMessageTool($this->errors, $http))->getSlug(),
            (new GetOutlookMessagesTool($this->errors, $http))->getSlug(),
            (new SendOutlookMailTool($this->errors, $http))->getSlug(),
            (new GetOnedriveFileTool($this->errors, $http))->getSlug(),
            (new ListOnedriveFilesTool($this->errors, $http))->getSlug(),
            (new UploadOnedriveFileTool($this->errors, $http))->getSlug(),
        );

        $this->assertSame(
            array(
                'get_teams_channels',
                'get_teams_messages',
                'send_teams_message',
                'get_outlook_messages',
                'send_outlook_mail',
                'get_onedrive_file',
                'list_onedrive_files',
                'upload_onedrive_file',
            ),
            $slugs
        );
    }

    public function test_every_tool_requires_manage_options(): void
    {
        $http = $this->http();

        foreach (array(new GetTeamsChannelsTool($this->errors, $http), new SendTeamsMessageTool($this->errors, $http)) as $tool) {
            $this->assertSame('manage_options', $tool->getRequiredCapability());
        }
    }

    public function test_a_write_refuses_an_unauthenticated_context(): void
    {
        $this->expectException(AccessDeniedException::class);

        (new SendTeamsMessageTool($this->errors, $this->http()))->execute(
            array('token' => self::TOKEN, 'team_id' => self::TEAM, 'channel_id' => self::CHANNEL, 'content' => 'Hi'),
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
