<?php

/**
 * The Cloudways reads, backups, rename and SSH-key creation.
 *
 * Proposal 164 section 164.142. What is pinned: the credentials endpoint
 * projects the connection tuple and nothing else, so a password in the response
 * body cannot reach the envelope; the vulnerability listing keeps WordPress's
 * three fields and survives a row with a missing key; the two backups post an
 * empty body to their own paths and return the API answer unchanged; the app
 * rename trims its label and does not HTML-escape it into a JSON message; and
 * the SSH-key tool sends `app_id: 0` for a server-level key and refuses private
 * key material before any request.
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
use Nvoos\Core\Tool\Cloudways\AppCredentialsTool;
use Nvoos\Core\Tool\Cloudways\AppVulnerabilitiesTool;
use Nvoos\Core\Tool\Cloudways\CreateAppBackupTool;
use Nvoos\Core\Tool\Cloudways\CreateServerBackupTool;
use Nvoos\Core\Tool\Cloudways\CreateSshKeyTool;
use Nvoos\Core\Tool\Cloudways\UpdateAppLabelTool;
use PHPUnit\Framework\TestCase;

final class CloudwaysBackupKeyToolsTest extends TestCase
{
    private const CONTEXT = array('user_id' => 2);

    private const TARGET = array('server_id' => 5, 'app_id' => 6);

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

    // ─── Credentials ─────────────────────────────────────────────────────

    public function test_credentials_project_the_connection_tuple_and_nothing_else(): void
    {
        $client = $this->client(array(
            'credentials' => array(
                'host'     => '203.0.113.9',
                'username' => 'master_abc12',
                'port'     => 2222,
                'password' => 'must-not-travel',
            ),
        ));

        $result = (new AppCredentialsTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('get', $client->calls[0]['verb']);
        $this->assertSame('/app/5/6/credentials', $client->calls[0]['path']);
        $this->assertSame(
            array('host' => '203.0.113.9', 'username' => 'master_abc12', 'port' => 2222),
            $result['data']['credentials']
        );
        $this->assertArrayNotHasKey('password', $result['data']['credentials']);
        $this->assertStringNotContainsString('must-not-travel', (string) json_encode($result));
        $this->assertSame('Credentials retrieved for app 6.', $result['message']);
    }

    public function test_credentials_default_a_missing_port_to_22(): void
    {
        $client = $this->client(array(
            'credentials' => array('host' => 'h.example', 'username' => 'u'),
        ));

        $result = (new AppCredentialsTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(22, $result['data']['credentials']['port']);
    }

    public function test_credentials_default_an_unusable_port_to_22(): void
    {
        $client = $this->client(array(
            'credentials' => array('host' => 'h.example', 'username' => 'u', 'port' => 'ssh'),
        ));

        $result = (new AppCredentialsTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(22, $result['data']['credentials']['port']);
    }

    public function test_credentials_name_a_body_without_the_object(): void
    {
        $result = (new AppCredentialsTool($this->errors, $this->client(array('credentials' => 'nope'))))
            ->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
    }

    public function test_credentials_require_an_app_id(): void
    {
        $client = $this->client(array());

        $result = (new AppCredentialsTool($this->errors, $client))->execute(
            array('server_id' => 5),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('app_id', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── Vulnerabilities ─────────────────────────────────────────────────

    public function test_vulnerabilities_project_three_fields(): void
    {
        $client = $this->client(array(
            'vulnerabilities' => array(
                array('id' => 101, 'title' => 'Plugin X RCE', 'severity' => 'critical', 'cvss' => 9.8),
                array('id' => 102, 'title' => 'Core LFI', 'severity' => 'high', 'cvss' => 7.5),
            ),
        ));

        $result = (new AppVulnerabilitiesTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('get', $client->calls[0]['verb']);
        $this->assertSame('/app/5/6/vulnerabilities', $client->calls[0]['path']);
        $this->assertSame(
            array(
                array('id' => 101, 'title' => 'Plugin X RCE', 'severity' => 'critical'),
                array('id' => 102, 'title' => 'Core LFI', 'severity' => 'high'),
            ),
            $result['data']['vulnerabilities']
        );
        $this->assertStringNotContainsString('cvss', (string) json_encode($result));
        $this->assertSame('Found 2 vulnerabilities.', $result['message']);
    }

    public function test_one_vulnerability_is_named_in_the_singular(): void
    {
        $client = $this->client(array(
            'vulnerabilities' => array(array('id' => 1, 'title' => 'Only one', 'severity' => 'low')),
        ));

        $result = (new AppVulnerabilitiesTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('Found 1 vulnerability.', $result['message']);
    }

    public function test_a_vulnerability_row_without_keys_is_empty_not_fatal(): void
    {
        $client = $this->client(array(
            'vulnerabilities' => array(array('title' => 'Sparse row'), 'not-an-array'),
        ));

        $result = (new AppVulnerabilitiesTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame(
            array(array('id' => 0, 'title' => 'Sparse row', 'severity' => '')),
            $result['data']['vulnerabilities']
        );
    }

    public function test_a_body_without_the_vulnerability_list_is_named(): void
    {
        $result = (new AppVulnerabilitiesTool($this->errors, $this->client(array('vulnerabilities' => null))))
            ->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_invalid_response', $result['error']['code']);
    }

    // ─── Backups ─────────────────────────────────────────────────────────

    public function test_app_backup_posts_an_empty_body_and_returns_the_answer(): void
    {
        $client = $this->client(array('operation_id' => 'op-app'));

        $result = (new CreateAppBackupTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertTrue($result['success']);
        $this->assertSame('post', $client->calls[0]['verb']);
        $this->assertSame('/app/5/6/backup', $client->calls[0]['path']);
        $this->assertSame(array(), $client->calls[0]['body']);
        $this->assertSame(array('result' => array('operation_id' => 'op-app')), $result['data']);
        $this->assertSame('Backup initiated for app 6.', $result['message']);
    }

    public function test_app_backup_keeps_the_api_classification(): void
    {
        $client         = $this->client(array());
        $client->throws = new CloudwaysApiException('Refused', 'cloudways_forbidden', 403);

        $result = (new CreateAppBackupTool($this->errors, $client))->execute(self::TARGET, self::CONTEXT);

        $this->assertSame('cloudways_forbidden', $result['error']['code']);
        $this->assertSame(403, $result['error']['data']['status']);
    }

    public function test_server_backup_posts_to_the_server_path(): void
    {
        $client = $this->client(array('operation_id' => 'op-server'));

        $result = (new CreateServerBackupTool($this->errors, $client))->execute(
            array('server_id' => 5),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('post', $client->calls[0]['verb']);
        $this->assertSame('/server/5/backup', $client->calls[0]['path']);
        $this->assertSame(array(), $client->calls[0]['body']);
        $this->assertSame('Backup initiated for server 5.', $result['message']);
    }

    public function test_server_backup_requires_a_server_id(): void
    {
        $client = $this->client(array());

        $result = (new CreateServerBackupTool($this->errors, $client))->execute(array(), self::CONTEXT);

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('server_id', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    // ─── App label ───────────────────────────────────────────────────────

    public function test_app_label_puts_a_trimmed_label(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new UpdateAppLabelTool($this->errors, $client))->execute(
            self::TARGET + array('label' => '  Release  '),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('put', $client->calls[0]['verb']);
        $this->assertSame('/app/5/6/label', $client->calls[0]['path']);
        $this->assertSame(array('label' => 'Release'), $client->calls[0]['body']);
        $this->assertSame('App renamed to "Release" (ID: 6).', $result['message']);
    }

    public function test_app_label_is_not_html_escaped_into_the_message(): void
    {
        $client = $this->client(array('ok' => true));

        $result = (new UpdateAppLabelTool($this->errors, $client))->execute(
            self::TARGET + array('label' => 'Ben & Jerry'),
            self::CONTEXT
        );

        $this->assertSame('Ben & Jerry', $client->calls[0]['body']['label']);
        $this->assertStringContainsString('Ben & Jerry', $result['message']);
        $this->assertStringNotContainsString('&amp;', $result['message']);
    }

    public function test_app_label_refuses_an_empty_label(): void
    {
        $client = $this->client(array());

        $result = (new UpdateAppLabelTool($this->errors, $client))->execute(
            self::TARGET + array('label' => '   '),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('label', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_app_label_requires_the_app_id(): void
    {
        $result = (new UpdateAppLabelTool($this->errors, $this->client(array())))->execute(
            array('server_id' => 5, 'label' => 'Release'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('app_id', $result['error']['data']);
    }

    // ─── SSH key creation ────────────────────────────────────────────────

    public function test_ssh_key_create_sends_zero_for_a_server_level_key(): void
    {
        $client = $this->client(array('id' => 77));

        $result = (new CreateSshKeyTool($this->errors, $client))->execute(
            array(
                'server_id'  => 5,
                'label'      => 'deploy',
                'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample me@host',
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertSame('post', $client->calls[0]['verb']);
        $this->assertSame('/ssh-key', $client->calls[0]['path']);
        $this->assertSame(
            array(
                'server_id'  => 5,
                'app_id'     => 0,
                'label'      => 'deploy',
                'public_key' => 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIExample me@host',
            ),
            $client->calls[0]['body']
        );
        $this->assertSame(array('id' => 77), $result['data']);
        $this->assertSame('SSH key "deploy" created successfully.', $result['message']);
    }

    public function test_ssh_key_create_carries_the_app_scope(): void
    {
        $client = $this->client(array('id' => 78));

        (new CreateSshKeyTool($this->errors, $client))->execute(
            array(
                'server_id'  => 5,
                'app_id'     => 9,
                'label'      => 'deploy',
                'public_key' => 'ssh-rsa AAAAB3NzaExample deploy@host',
            ),
            self::CONTEXT
        );

        $this->assertSame(9, $client->calls[0]['body']['app_id']);
    }

    public function test_ssh_key_create_refuses_private_key_material(): void
    {
        $client = $this->client(array());

        $result = (new CreateSshKeyTool($this->errors, $client))->execute(
            array(
                'server_id'  => 5,
                'label'      => 'oops',
                'public_key' => "-----BEGIN OPENSSH PRIVATE KEY-----\nb3BlbnNzaC1rZXk\n-----END OPENSSH PRIVATE KEY-----",
            ),
            self::CONTEXT
        );

        $this->assertSame('cloudways_private_key_rejected', $result['error']['code']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_ssh_key_create_accepts_a_public_key_pem_body(): void
    {
        $client = $this->client(array('id' => 79));

        $result = (new CreateSshKeyTool($this->errors, $client))->execute(
            array(
                'server_id'  => 5,
                'label'      => 'pem',
                'public_key' => "-----BEGIN PUBLIC KEY-----\nAAAAB3NzaC1yc2E\n-----END PUBLIC KEY-----",
            ),
            self::CONTEXT
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $client->calls);
    }

    public function test_ssh_key_create_requires_a_public_key(): void
    {
        $client = $this->client(array());

        $result = (new CreateSshKeyTool($this->errors, $client))->execute(
            array('server_id' => 5, 'label' => 'deploy', 'public_key' => '   '),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('public_key', $result['error']['data']);
        $this->assertSame(array(), $client->calls);
    }

    public function test_ssh_key_create_requires_a_label(): void
    {
        $result = (new CreateSshKeyTool($this->errors, $this->client(array())))->execute(
            array('server_id' => 5, 'public_key' => 'ssh-ed25519 AAAA me@host'),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertArrayHasKey('label', $result['error']['data']);
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
