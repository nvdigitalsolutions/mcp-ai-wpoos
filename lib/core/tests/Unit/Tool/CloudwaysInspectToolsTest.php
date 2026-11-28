<?php

/**
 * The three Cloudways inspectors: projects, SSH keys and server settings.
 *
 * Proposal 164 section 164.133. What is pinned here: the projection drops
 * non-row entries instead of warning on them, the server id travels as a query
 * argument rather than hand-built URL text, a refusal keeps its classification,
 * and the settings tool returns the whole scalar block rather than the three
 * versions WordPress projected.
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
use Nvoos\Core\Tool\Cloudways\ListProjectsTool;
use Nvoos\Core\Tool\Cloudways\ListSshKeysTool;
use Nvoos\Core\Tool\Cloudways\ServerSettingsTool;
use PHPUnit\Framework\TestCase;

final class CloudwaysInspectToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

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

    // ─── cloudways_list_projects ─────────────────────────────────────────

    public function test_projects_are_projected_and_non_rows_dropped(): void
    {
        $client = $this->client(
            array(
                'projects' => array(
                    array('id' => '42', 'name' => 'Client sites'),
                    array('id' => 43, 'name' => 'Internal'),
                    'not-a-row',
                ),
            )
        );

        $result = $this->projects($client)->execute(array(), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                array('id' => 42, 'name' => 'Client sites'),
                array('id' => 43, 'name' => 'Internal'),
            ),
            $result['data']['projects']
        );
        $this->assertSame('Found 2 projects.', $result['message']);
        $this->assertSame('/project', $client->calls[0]['path']);
    }

    public function test_a_missing_projects_block_is_malformed(): void
    {
        $result = $this->projects($this->client(array()))->execute(array(), self::CONTEXT);

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
        $this->assertStringContainsString('projects', $result['error']['message']);
    }

    // ─── cloudways_ssh_key_list ──────────────────────────────────────────

    public function test_ssh_keys_are_projected_with_the_server_as_a_query(): void
    {
        $client = $this->client(
            array(
                'ssh_keys' => array(
                    array('id' => '9', 'label' => 'laptop', 'key_type' => 'ssh-rsa', 'user' => 'master'),
                    array('id' => 10, 'public_key' => 'ssh-rsa AAAA secret material'),
                ),
            )
        );

        $result = $this->keys($client)->execute(array('server_id' => 321), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('/ssh-key', $client->calls[0]['path']);
        $this->assertSame(array('server_id' => 321), $client->calls[0]['query']);
        $this->assertSame(
            array(
                'id'       => 9,
                'label'    => 'laptop',
                'key_type' => 'ssh-rsa',
                'user'     => 'master',
            ),
            $result['data']['ssh_keys'][0]
        );
        // The key material is not part of the contract.
        $this->assertArrayNotHasKey('public_key', $result['data']['ssh_keys'][1]);
        $this->assertSame('Found 2 SSH keys.', $result['message']);
    }

    public function test_a_missing_server_id_is_refused_before_the_request(): void
    {
        $client = $this->client(array('ssh_keys' => array()));

        $result = $this->keys($client)->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_a_missing_trailing_ssh_key_hyphen_is_not_read_as_a_count(): void
    {
        $client = $this->client(array('ssh_keys' => array()));

        $result = $this->keys($client)->execute(array('server_id' => 1), self::CONTEXT);

        $this->assertSame('Found 0 SSH keys.', $result['message']);
    }

    // ─── cloudways_server_settings_get ───────────────────────────────────

    public function test_server_settings_keep_the_three_keys_and_return_every_scalar(): void
    {
        $client = $this->client(
            array(
                'settings' => array(
                    'php_version'   => '8.3',
                    'mysql_version' => '8.0',
                    'nginx_version' => '1.24',
                    'memory_limit'  => '512M',
                    'max_input_vars' => 5000,
                    'php_settings'  => array('memory_limit' => '512M'),
                ),
            )
        );

        $result = $this->settings($client)->execute(array('server_id' => 55), self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame(
            array(
                'php_version'   => '8.3',
                'mysql_version' => '8.0',
                'nginx_version' => '1.24',
            ),
            $result['data']['settings']
        );
        $this->assertSame(
            array(
                'php_version'    => '8.3',
                'mysql_version'  => '8.0',
                'nginx_version'  => '1.24',
                'memory_limit'   => '512M',
                'max_input_vars' => 5000,
            ),
            $result['data']['values']
        );
        $this->assertSame('/server/55/settings', $client->calls[0]['path']);
        $this->assertSame('Settings retrieved for server 55.', $result['message']);
    }

    public function test_a_missing_settings_block_is_malformed(): void
    {
        $result = $this->settings($this->client(array()))->execute(
            array('server_id' => 55),
            self::CONTEXT
        );

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
    }

    public function test_a_missing_server_id_is_refused(): void
    {
        $result = $this->settings($this->client(array()))->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── A refused call ──────────────────────────────────────────────────

    public function test_an_api_refusal_keeps_its_classification(): void
    {
        $client = $this->client(array());
        $client->throws = new CloudwaysApiException('Not found', 'cloudways_not_found', 404);

        $result = $this->projects($client)->execute(array(), self::CONTEXT);

        $this->assertFalse($result['success']);
        $this->assertSame('cloudways_not_found', $result['error']['code']);
        $this->assertSame(404, $result['error']['data']['status']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    private function projects(CloudwaysClientInterface $client): ListProjectsTool
    {
        return new ListProjectsTool($this->errors, $client);
    }

    private function keys(CloudwaysClientInterface $client): ListSshKeysTool
    {
        return new ListSshKeysTool($this->errors, $client);
    }

    private function settings(CloudwaysClientInterface $client): ServerSettingsTool
    {
        return new ServerSettingsTool($this->errors, $client);
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
