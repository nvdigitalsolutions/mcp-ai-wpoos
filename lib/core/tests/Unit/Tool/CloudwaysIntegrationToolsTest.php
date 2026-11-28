<?php

/**
 * The Cloudways integration group: add-ons, Cloudflare, DNS Made Easy, Copilot.
 *
 * Proposal 164 section 164.141. What is pinned: the DNS tools validate the
 * caller's domain as a host and encode the path segment, because
 * `sanitize_text_field()` leaves `..` intact and WordPress interpolated it into
 * a request made with the account token; no tool HTML-escapes a value inside a
 * JSON envelope; and the Copilot filters the WordPress schema advertised are
 * actually forwarded instead of silently ignored.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\CloudwaysClientInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\CloudwaysApiException;
use Nvoos\Core\Tool\Cloudways\AddonActivateTool;
use Nvoos\Core\Tool\Cloudways\AddonListTool;
use Nvoos\Core\Tool\Cloudways\CloudflareAddDomainTool;
use Nvoos\Core\Tool\Cloudways\CloudflareDetailsTool;
use Nvoos\Core\Tool\Cloudways\CopilotInsightsTool;
use Nvoos\Core\Tool\Cloudways\DnsDomainsTool;
use Nvoos\Core\Tool\Cloudways\DnsRecordAddTool;
use Nvoos\Core\Tool\Cloudways\DnsRecordsTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CloudwaysIntegrationToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 1);

    private const TARGET = array('server_id' => 4, 'app_id' => 5);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

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

    // ─── add-ons ─────────────────────────────────────────────────────────

    public function test_addons_keep_the_identifier_as_a_string(): void
    {
        $client = $this->client(
            array(
                'addons' => array(
                    array(
                        'id'          => 'dnsmadeeasy',
                        'name'        => 'DNS Made Easy',
                        'description' => 'Managed DNS',
                        'status'      => 'active',
                        'price'       => '5.00',
                    ),
                    'not-a-row',
                ),
            )
        );

        $result = (new AddonListTool($this->errors, $client))->execute(array(), self::CONTEXT);

        $this->assertSame(
            array(
                array(
                    'id'          => 'dnsmadeeasy',
                    'name'        => 'DNS Made Easy',
                    'description' => 'Managed DNS',
                    'status'      => 'active',
                    'price'       => '5.00',
                ),
            ),
            $result['data']['addons']
        );
        $this->assertSame('/addon', $client->calls[0]['path']);
        $this->assertSame('Found 1 add-on.', $result['message']);
    }

    public function test_activation_posts_the_identifier_and_does_not_escape_it(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new AddonActivateTool($this->errors, $client))->execute(
            array('addon' => 'DNS Made Easy'),
            self::CONTEXT
        );

        $this->assertSame('/addon/activate', $client->calls[0]['path']);
        // The identifier is a slug, so it is reduced to one; no entity survives
        // the message because no escapable character survives the identifier.
        $this->assertSame('dnsmadeeasy', $client->calls[0]['body']['addon']);
        $this->assertStringContainsString('dnsmadeeasy', $result['message']);
        $this->assertStringNotContainsString('&amp;', $result['message']);
    }

    public function test_activation_requires_an_identifier(): void
    {
        $client = $this->client(array());

        $result = (new AddonActivateTool($this->errors, $client))->execute(array('addon' => '  '), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── Cloudflare ──────────────────────────────────────────────────────

    public function test_cloudflare_details_project_three_fields(): void
    {
        $client = $this->client(
            array('cloudflare' => array('status' => 'active', 'enabled' => '1', 'dns_configured' => '0'))
        );

        $result = (new CloudflareDetailsTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(
            array('status' => 'active', 'enabled' => true, 'dns_configured' => false),
            $result['data']['cloudflare']
        );
        $this->assertSame('/app/4/5/cloudflare', $client->calls[0]['path']);
    }

    public function test_a_domain_is_added_after_a_host_check(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new CloudflareAddDomainTool($this->errors, $client))->execute(
            self::TARGET + array('domain' => ' example.com '),
            self::CONTEXT
        );

        $this->assertSame('/app/4/5/cloudflare/domain', $client->calls[0]['path']);
        $this->assertSame(array('domain' => 'example.com'), $client->calls[0]['body']);
        $this->assertSame('Domain "example.com" added to Cloudflare CDN.', $result['message']);
    }

    public function test_a_pasted_url_is_refused_as_a_domain(): void
    {
        $client = $this->client(array());

        $result = (new CloudflareAddDomainTool($this->errors, $client))->execute(
            self::TARGET + array('domain' => 'https://example.com/'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('domain', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── DNS ─────────────────────────────────────────────────────────────

    public function test_dns_domains_project_three_fields(): void
    {
        $client = $this->client(
            array('domains' => array(array('id' => '9', 'name' => 'example.com', 'status' => 'Active')))
        );

        $result = (new DnsDomainsTool($this->errors, $client))->execute(array(), self::CONTEXT);

        $this->assertSame(
            array(array('id' => 9, 'name' => 'example.com', 'status' => 'Active')),
            $result['data']['domains']
        );
    }

    public function test_dns_records_are_read_from_an_encoded_path(): void
    {
        $client = $this->client(
            array(
                'records' => array(
                    array('id' => '1', 'type' => 'A', 'name' => '@', 'value' => '203.0.113.1', 'ttl' => '3600'),
                    'not-a-row',
                ),
            )
        );

        $result = (new DnsRecordsTool($this->errors, $client))->execute(
            array('domain' => 'example.com'),
            self::CONTEXT
        );

        $this->assertSame('/dns/domain/example.com/record', $client->calls[0]['path']);
        $this->assertSame(
            array(array('id' => 1, 'type' => 'A', 'name' => '@', 'value' => '203.0.113.1', 'ttl' => 3600)),
            $result['data']['records']
        );
        $this->assertSame('Found 1 record for example.com.', $result['message']);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function traversals(): array
    {
        return array(
            'parent segments' => array('../../server/1'),
            'slash'           => array('example.com/record'),
            'scheme'          => array('https://example.com'),
            'colon'           => array('example.com:8080'),
        );
    }

    #[DataProvider('traversals')]
    public function test_a_path_traversal_is_refused_before_the_request(string $domain): void
    {
        $client = $this->client(array());

        $result = (new DnsRecordsTool($this->errors, $client))->execute(
            array('domain' => $domain),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    #[DataProvider('traversals')]
    public function test_the_record_writer_refuses_a_traversal_too(string $domain): void
    {
        $client = $this->client(array());

        $result = (new DnsRecordAddTool($this->errors, $client))->execute(
            array(
                'domain' => $domain,
                'type'   => 'A',
                'name'   => 'www',
                'value'  => '203.0.113.1',
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_record_is_added_with_a_ttl(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new DnsRecordAddTool($this->errors, $client))->execute(
            array(
                'domain' => 'example.com',
                'type'   => 'TXT',
                'name'   => 'v=spf1',
                'value'  => 'v=spf1 -all',
                'ttl'    => 300,
            ),
            self::CONTEXT
        );

        $this->assertSame('/dns/domain/example.com/record', $client->calls[0]['path']);
        $this->assertSame(
            array('type' => 'TXT', 'name' => 'v=spf1', 'value' => 'v=spf1 -all', 'ttl' => 300),
            $client->calls[0]['body']
        );
        $this->assertSame('TXT record "v=spf1" added to example.com.', $result['message']);
    }

    public function test_an_unknown_record_type_is_refused(): void
    {
        $client = $this->client(array());

        $result = (new DnsRecordAddTool($this->errors, $client))->execute(
            array('domain' => 'example.com', 'type' => 'SRV', 'name' => '_sip', 'value' => 'x'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('type', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_negative_ttl_is_named_not_flipped(): void
    {
        $client = $this->client(array());

        $result = (new DnsRecordAddTool($this->errors, $client))->execute(
            array('domain' => 'example.com', 'type' => 'A', 'name' => 'www', 'value' => '203.0.113.1', 'ttl' => -300),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('ttl', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── Copilot ─────────────────────────────────────────────────────────

    public function test_copilot_forwards_the_filters_wordpress_ignored(): void
    {
        $client = $this->client(array('insights' => array()));

        (new CopilotInsightsTool($this->errors, $client))->execute(
            array('server_id' => 4, 'app_id' => 5),
            self::CONTEXT
        );

        $this->assertSame('/copilot/insights', $client->calls[0]['path']);
        $this->assertSame(array('server_id' => 4, 'app_id' => 5), $client->calls[0]['query']);
    }

    public function test_copilot_sends_no_query_when_unfiltered(): void
    {
        $client = $this->client(array('insights' => array()));

        (new CopilotInsightsTool($this->errors, $client))->execute(array(), self::CONTEXT);

        $this->assertSame(array(), $client->calls[0]['query']);
    }

    public function test_copilot_projects_six_fields(): void
    {
        $client = $this->client(
            array(
                'insights' => array(
                    array(
                        'id'          => 'i1',
                        'title'       => 'Disk filling',
                        'description' => 'Server 4 is at 91%.',
                        'severity'    => 'warning',
                        'category'    => 'disk',
                        'timestamp'   => '2026-03-01T10:00:00Z',
                    ),
                    'not-a-row',
                ),
            )
        );

        $result = (new CopilotInsightsTool($this->errors, $client))->execute(array(), self::CONTEXT);

        $this->assertCount(1, $result['data']['insights']);
        $this->assertSame('i1', $result['data']['insights'][0]['id']);
        $this->assertSame('warning', $result['data']['insights'][0]['severity']);
    }

    public function test_an_api_refusal_keeps_its_classification(): void
    {
        $client         = $this->client(array());
        $client->throws = new CloudwaysApiException('Nope', 'cloudways_forbidden', 403);

        $result = (new AddonListTool($this->errors, $client))->execute(array(), self::CONTEXT);

        $this->assertSame('cloudways_forbidden', $result['error']['code']);
        $this->assertSame(403, $result['error']['data']['status']);
    }

    /**
     * A recorder client that answers the same payload for every verb.
     *
     * @param array<string, mixed> $payload Response body.
     */
    private function client(array $payload = array()): CloudwaysClientInterface
    {
        return new class ($payload) implements CloudwaysClientInterface {
            /** @var list<array{verb: string, path: string, query: array<string, mixed>, body: array<string, mixed>}> */
            public array $calls = array();

            public ?\Throwable $throws = null;

            /**
             * @param array<string, mixed> $payload Response body.
             */
            public function __construct(private array $payload)
            {
            }

            public function isConfigured(): bool
            {
                return true;
            }

            public function get(string $path, array $query = array()): array
            {
                return $this->record('get', $path, $query, array());
            }

            public function post(string $path, array $body = array()): array
            {
                return $this->record('post', $path, array(), $body);
            }

            public function put(string $path, array $body = array()): array
            {
                return $this->record('put', $path, array(), $body);
            }

            public function delete(string $path, array $body = array()): array
            {
                return $this->record('delete', $path, array(), $body);
            }

            /**
             * @param array<string, mixed> $query Query.
             * @param array<string, mixed> $body  Body.
             *
             * @return array<string, mixed>
             */
            private function record(string $verb, string $path, array $query, array $body): array
            {
                $this->calls[] = array('verb' => $verb, 'path' => $path, 'query' => $query, 'body' => $body);

                if (null !== $this->throws) {
                    throw $this->throws;
                }

                return $this->payload;
            }
        };
    }
}
