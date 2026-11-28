<?php

/**
 * The three Cloudways deletes that close the family.
 *
 * Proposal 164 section 164.143. What is pinned: every delete refuses without
 * the literal boolean `confirm: true` and sends nothing; a negative id is named
 * rather than flipped by `absint`; the DNS delete treats the domain as a single
 * path segment, so a traversal value never becomes part of the URL; and the
 * success messages carry caller values raw rather than HTML-escaped.
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
use Nvoos\Core\Tool\Cloudways\DeleteSshKeyTool;
use Nvoos\Core\Tool\Cloudways\DnsRecordDeleteTool;
use Nvoos\Core\Tool\Cloudways\ServerDeleteTool;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CloudwaysDeleteToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 2);

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

    // ─── SSH key ─────────────────────────────────────────────────────────

    public function test_ssh_key_delete_calls_the_key_path(): void
    {
        $client = $this->client(array('deleted' => true));

        $result = (new DeleteSshKeyTool($this->errors, $client))->execute(
            array('ssh_key_id' => 77, 'confirm' => true),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('delete', $client->calls[0]['verb']);
        $this->assertSame('/ssh-key/77', $client->calls[0]['path']);
        $this->assertSame(array('result' => array('deleted' => true)), $result['data']);
        $this->assertSame('SSH key 77 deleted successfully.', $result['message']);
    }

    public function test_ssh_key_delete_refuses_a_truthy_string_confirm(): void
    {
        $client = $this->client(array());

        $result = (new DeleteSshKeyTool($this->errors, $client))->execute(
            array('ssh_key_id' => 77, 'confirm' => 'true'),
            self::CONTEXT
        );

        $this->assertSame('cloudways_confirm_required', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_ssh_key_delete_names_a_negative_id_instead_of_flipping_it(): void
    {
        $client = $this->client(array());

        $result = (new DeleteSshKeyTool($this->errors, $client))->execute(
            array('ssh_key_id' => -77, 'confirm' => true),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('ssh_key_id', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_ssh_key_delete_keeps_an_api_refusal(): void
    {
        $client         = $this->client(array());
        $client->throws = new CloudwaysApiException('Refused', 'cloudways_forbidden', 403);

        $result = (new DeleteSshKeyTool($this->errors, $client))->execute(
            array('ssh_key_id' => 77, 'confirm' => true),
            self::CONTEXT
        );

        $this->assertSame('cloudways_forbidden', $result['error']['code']);
        $this->assertSame(403, $result['error']['data']['status']);
    }

    // ─── DNS record ──────────────────────────────────────────────────────

    public function test_dns_delete_calls_the_record_path(): void
    {
        $client = $this->client(array('deleted' => true));

        $result = (new DnsRecordDeleteTool($this->errors, $client))->execute(
            array('domain' => 'example.com', 'record_id' => 12, 'confirm' => true),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('delete', $client->calls[0]['verb']);
        $this->assertSame('/dns/domain/example.com/record/12', $client->calls[0]['path']);
        $this->assertSame('DNS record 12 deleted from example.com.', $result['message']);
    }

    /**
     * A domain is one path segment, so a value that can leave it is refused.
     *
     * @return array<string, array{string}>
     */
    public static function traversalDomains(): array
    {
        return array(
            'parent segment' => array('../server/1'),
            'deep parent'    => array('../../app/1/2'),
            'slash'          => array('example.com/record/9'),
            'scheme'         => array('https://example.com'),
            'space'          => array('example com'),
        );
    }

    #[DataProvider('traversalDomains')]
    public function test_dns_delete_refuses_a_domain_that_is_not_a_host(string $domain): void
    {
        $client = $this->client(array());

        $result = (new DnsRecordDeleteTool($this->errors, $client))->execute(
            array('domain' => $domain, 'record_id' => 12, 'confirm' => true),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('domain', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_dns_delete_encodes_the_domain_and_keeps_it_raw_in_the_message(): void
    {
        $client = $this->client(array('deleted' => true));

        $result = (new DnsRecordDeleteTool($this->errors, $client))->execute(
            array('domain' => 'a&b.example', 'record_id' => 3, 'confirm' => true),
            self::CONTEXT
        );

        $this->assertSame('/dns/domain/a%26b.example/record/3', $client->calls[0]['path']);
        $this->assertStringContainsString('a&b.example', $result['message']);
        $this->assertStringNotContainsString('&amp;', $result['message']);
    }

    public function test_dns_delete_refuses_without_confirm(): void
    {
        $client = $this->client(array());

        $result = (new DnsRecordDeleteTool($this->errors, $client))->execute(
            array('domain' => 'example.com', 'record_id' => 12),
            self::CONTEXT
        );

        $this->assertSame('cloudways_confirm_required', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_dns_delete_requires_a_record_id(): void
    {
        $result = (new DnsRecordDeleteTool($this->errors, $this->client(array())))->execute(
            array('domain' => 'example.com', 'record_id' => 0, 'confirm' => true),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('record_id', $result['error']['data']);
    }

    // ─── Server ──────────────────────────────────────────────────────────

    public function test_server_delete_calls_the_server_path(): void
    {
        $client = $this->client(array('deleted' => true));

        $result = (new ServerDeleteTool($this->errors, $client))->execute(
            array('server_id' => 5, 'confirm' => true),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('delete', $client->calls[0]['verb']);
        $this->assertSame('/server/5', $client->calls[0]['path']);
        $this->assertSame('Server permanently deleted.', $result['message']);
    }

    public function test_server_delete_refuses_a_false_confirm(): void
    {
        $client = $this->client(array());

        $result = (new ServerDeleteTool($this->errors, $client))->execute(
            array('server_id' => 5, 'confirm' => false),
            self::CONTEXT
        );

        $this->assertSame('cloudways_confirm_required', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_server_delete_requires_a_server_id(): void
    {
        $result = (new ServerDeleteTool($this->errors, $this->client(array())))->execute(
            array('server_id' => 0, 'confirm' => true),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('server_id', $result['error']['data']);
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
